<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the default `bug-fix-merge` workflow for a team.
 *
 * Topology:
 *
 *   Start
 *     ↓
 *   Agent (the team's bug-fix-agent — fills in agent_id at materialization time)
 *     ↓
 *   ClassifyPrTier (reads pr_url from Agent output)
 *     ↓
 *   Conditional `tier_route` (case_value matches tier output)
 *     ├─ T1   → BitbucketPrMerge → End            (auto-merge for trivial fixes)
 *     ├─ T2   → HumanTask → BitbucketPrMerge → End  (operators may flip to direct merge)
 *     ├─ T3   → HumanTask → BitbucketPrMerge → End  (operators may flip — risk-bearing)
 *     ├─ T4   → HumanTask → BitbucketPrMerge → End  (server-side floor: never bypassable)
 *     └─ default → End                              (classifier error → no merge)
 *
 * The T4 path is locked to a HumanTask by the GraphValidator's
 * validateT4HumanTaskFloor invariant. The other tiers are editable per
 * project — operators can collapse T2/T3 to direct merge if they accept
 * the risk profile.
 *
 * The template is created in WorkflowStatus::Draft and only activated
 * when the operator runs `php artisan workflow:seed-bug-fix-merge
 * --team={teamId} --activate`.
 */
class BugFixMergeTemplateBuilder
{
    public function __construct(
        private readonly GraphValidator $validator,
    ) {}

    public function buildForTeam(string $teamId, ?string $userId = null, ?string $defaultAgentId = null): Workflow
    {
        return DB::transaction(function () use ($teamId, $userId, $defaultAgentId) {
            $workflow = Workflow::create([
                'team_id' => $teamId,
                'user_id' => $userId,
                'name' => 'bug-fix-merge',
                'slug' => 'bug-fix-merge-'.Str::random(6),
                'description' => 'Default tier-gated merge pipeline for bug-fix agents. Classifies the agent\'s PR into T1-T4 risk tiers and routes to either auto-merge or human approval.',
                'status' => WorkflowStatus::Draft,
                'max_loop_iterations' => 1,
                'settings' => ['template' => 'bug-fix-merge'],
                'budget_cap_credits' => null,
            ]);

            $start = $this->createNode($workflow->id, WorkflowNodeType::Start, 'Start', 0, 250, 50);
            $agent = $this->createNode(
                $workflow->id,
                WorkflowNodeType::Agent,
                'Bug-fix Agent',
                1,
                250,
                150,
                config: ['prompt' => 'Diagnose the bug, write a fix on a feature branch, open a PR, and report the PR URL.'],
                agentId: $defaultAgentId,
            );
            $classify = $this->createNode(
                $workflow->id,
                WorkflowNodeType::ClassifyPrTier,
                'Classify PR Tier',
                2,
                250,
                250,
                config: [
                    'pr_url' => '{{'.$agent->id.'.pr_url}}',
                    'credential_id' => '',
                    'promote_branch' => 'main',
                ],
            );
            $conditional = $this->createNode(
                $workflow->id,
                WorkflowNodeType::Conditional,
                'tier_route',
                3,
                250,
                350,
            );

            $humanTaskT2 = $this->createNode(
                $workflow->id,
                WorkflowNodeType::HumanTask,
                'Human Review (T2)',
                4,
                100,
                450,
                config: ['form_schema' => $this->approvalFormSchema('T2')],
            );
            $humanTaskT3 = $this->createNode(
                $workflow->id,
                WorkflowNodeType::HumanTask,
                'Human Review (T3)',
                5,
                250,
                450,
                config: ['form_schema' => $this->approvalFormSchema('T3')],
            );
            $humanTaskT4 = $this->createNode(
                $workflow->id,
                WorkflowNodeType::HumanTask,
                'Human Review (T4)',
                6,
                400,
                450,
                config: ['form_schema' => $this->approvalFormSchema('T4')],
            );

            $merge = $this->createNode(
                $workflow->id,
                WorkflowNodeType::BitbucketPrMerge,
                'Merge PR',
                7,
                250,
                550,
                config: [
                    'pr_url' => '{{'.$classify->id.'.pr_url}}',
                    'credential_id' => '',
                    'merge_strategy' => 'merge_commit',
                ],
            );
            $end = $this->createNode($workflow->id, WorkflowNodeType::End, 'End', 8, 250, 650);

            // Linear edges
            $this->createEdge($workflow->id, $start->id, $agent->id);
            $this->createEdge($workflow->id, $agent->id, $classify->id);
            $this->createEdge($workflow->id, $classify->id, $conditional->id);

            // T1 → direct merge (auto-merge path)
            $this->createEdge($workflow->id, $conditional->id, $merge->id, caseValue: 'T1', label: 'T1: trivial');

            // T2/T3/T4 → human review then merge
            $this->createEdge($workflow->id, $conditional->id, $humanTaskT2->id, caseValue: 'T2', label: 'T2: medium');
            $this->createEdge($workflow->id, $humanTaskT2->id, $merge->id);

            $this->createEdge($workflow->id, $conditional->id, $humanTaskT3->id, caseValue: 'T3', label: 'T3: high-risk');
            $this->createEdge($workflow->id, $humanTaskT3->id, $merge->id);

            $this->createEdge($workflow->id, $conditional->id, $humanTaskT4->id, caseValue: 'T4', label: 'T4: promote-branch (locked)');
            $this->createEdge($workflow->id, $humanTaskT4->id, $merge->id);

            // Default branch — classifier error or unknown tier → end without merging
            $this->createEdge($workflow->id, $conditional->id, $end->id, isDefault: true, label: 'fallback');

            // Merge → End
            $this->createEdge($workflow->id, $merge->id, $end->id);

            return $workflow->fresh(['nodes', 'edges']);
        });
    }

    /**
     * Validate the workflow against GraphValidator invariants. Returns errors
     * (empty when valid).
     *
     * @return array<int, array<string, mixed>>
     */
    public function validate(Workflow $workflow): array
    {
        return $this->validator->validate($workflow);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createNode(
        string $workflowId,
        WorkflowNodeType $type,
        string $label,
        int $order,
        int $x,
        int $y,
        array $config = [],
        ?string $agentId = null,
    ): WorkflowNode {
        return WorkflowNode::create([
            'workflow_id' => $workflowId,
            'agent_id' => $agentId,
            'type' => $type,
            'label' => $label,
            'position_x' => $x,
            'position_y' => $y,
            'config' => $config,
            'order' => $order,
        ]);
    }

    private function createEdge(
        string $workflowId,
        string $sourceId,
        string $targetId,
        ?string $caseValue = null,
        ?string $label = null,
        bool $isDefault = false,
    ): WorkflowEdge {
        return WorkflowEdge::create([
            'workflow_id' => $workflowId,
            'source_node_id' => $sourceId,
            'target_node_id' => $targetId,
            'case_value' => $caseValue,
            'label' => $label,
            'is_default' => $isDefault,
        ]);
    }

    /**
     * Approval form schema for the human-review nodes. Renders the tier badge,
     * reason, files list, test count, and PR link in the approval inbox.
     *
     * @return array<string, mixed>
     */
    private function approvalFormSchema(string $tier): array
    {
        return [
            'fields' => [
                ['name' => 'pr_url', 'label' => 'PR URL', 'type' => 'url', 'readonly' => true],
                ['name' => 'tier', 'label' => 'Tier', 'type' => 'badge', 'readonly' => true, 'value' => $tier],
                ['name' => 'reason', 'label' => 'Why this tier', 'type' => 'text', 'readonly' => true],
                ['name' => 'files_changed', 'label' => 'Files changed', 'type' => 'list', 'readonly' => true],
                ['name' => 'lines_changed', 'label' => 'Lines changed', 'type' => 'number', 'readonly' => true],
                ['name' => 'decision', 'label' => 'Decision', 'type' => 'select', 'options' => ['approve', 'reject']],
                ['name' => 'note', 'label' => 'Reviewer note', 'type' => 'textarea', 'required' => false],
            ],
        ];
    }
}
