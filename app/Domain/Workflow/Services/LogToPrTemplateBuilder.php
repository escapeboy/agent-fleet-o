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
 * Builds the default `log-to-pr` workflow for a team — a borrowed pattern
 * inspired by prilog.ai's log-remediation pipeline.
 *
 * Topology:
 *
 *   Start
 *     ↓
 *   Agent (RCA — root cause analysis from the inbound log signal)
 *     ↓
 *   Agent (Patch — drafts the code fix on a feature branch)
 *     ↓
 *   HumanTask (Review — skippable via skip_if_trusted when patch confidence ≥ threshold)
 *     ↓
 *   Agent (Open PR — calls git_pull_request_create MCP tool, body includes provenance trail)
 *     ↓
 *   End
 *
 * The template is created in WorkflowStatus::Draft and only activated
 * when the operator runs `php artisan workflow:seed-log-to-pr
 * --team={teamId} --activate`.
 */
class LogToPrTemplateBuilder
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
                'name' => 'log-to-pr',
                'slug' => 'log-to-pr-'.Str::random(6),
                'description' => 'Borrowed from prilog.ai. Receives a log/alert signal, runs RCA, drafts a patch, optionally pauses for human review, and opens a PR with full provenance in the body.',
                'status' => WorkflowStatus::Draft,
                'max_loop_iterations' => 1,
                'settings' => ['template' => 'log-to-pr'],
                'budget_cap_credits' => null,
            ]);

            $start = $this->createNode($workflow->id, WorkflowNodeType::Start, 'Start', 0, 250, 50);

            $rca = $this->createNode(
                $workflow->id,
                WorkflowNodeType::Agent,
                'Root Cause Analysis',
                1,
                250,
                150,
                config: [
                    'prompt' => 'You receive a log/alert signal in the input context. Identify the root cause: pinpoint the exact code path (file + line range) implicated by the stack trace or log message. Output JSON: { "root_cause": string, "affected_files": string[], "confidence": float (0..1), "reasoning": string }.',
                ],
                agentId: $defaultAgentId,
            );

            $patch = $this->createNode(
                $workflow->id,
                WorkflowNodeType::Agent,
                'Draft Patch',
                2,
                250,
                250,
                config: [
                    'prompt' => 'Given the root cause from the previous step, write a code fix. Open a feature branch, commit the patch, and report the diff. Output JSON: { "branch": string, "diff_summary": string, "files_changed": string[], "confidence": float (0..1) }.',
                ],
                agentId: $defaultAgentId,
            );

            $review = $this->createNode(
                $workflow->id,
                WorkflowNodeType::HumanTask,
                'Human Review',
                3,
                250,
                350,
                config: [
                    'form_schema' => $this->approvalFormSchema(),
                    // Borrowed pattern: optional bypass when the upstream agent reports high confidence.
                    'skip_if_trusted' => false,
                    'confidence_threshold' => 0.85,
                    'confidence_source_node_id' => $patch->id,
                ],
            );

            $openPr = $this->createNode(
                $workflow->id,
                WorkflowNodeType::Agent,
                'Open Pull Request',
                4,
                250,
                450,
                config: [
                    'prompt' => 'Open a pull request for the patch from step 2 using the git_pull_request_create tool. In the PR body, include the provenance summary returned by the experiment_provenance tool — signal source, RCA reasoning, patch summary, and agent IDs/models used.',
                ],
                agentId: $defaultAgentId,
            );

            $end = $this->createNode($workflow->id, WorkflowNodeType::End, 'End', 5, 250, 550);

            $this->createEdge($workflow->id, $start->id, $rca->id);
            $this->createEdge($workflow->id, $rca->id, $patch->id);
            $this->createEdge($workflow->id, $patch->id, $review->id);
            $this->createEdge($workflow->id, $review->id, $openPr->id);
            $this->createEdge($workflow->id, $openPr->id, $end->id);

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
     * @return array<string, mixed>
     */
    private function approvalFormSchema(): array
    {
        return [
            'fields' => [
                ['name' => 'signal_summary', 'label' => 'Triggering signal', 'type' => 'text', 'readonly' => true],
                ['name' => 'root_cause', 'label' => 'Root cause', 'type' => 'textarea', 'readonly' => true],
                ['name' => 'affected_files', 'label' => 'Affected files', 'type' => 'list', 'readonly' => true],
                ['name' => 'branch', 'label' => 'Patch branch', 'type' => 'text', 'readonly' => true],
                ['name' => 'diff_summary', 'label' => 'Diff summary', 'type' => 'textarea', 'readonly' => true],
                ['name' => 'confidence', 'label' => 'Agent confidence', 'type' => 'number', 'readonly' => true],
                ['name' => 'decision', 'label' => 'Decision', 'type' => 'select', 'options' => ['approve', 'reject']],
                ['name' => 'note', 'label' => 'Reviewer note', 'type' => 'textarea', 'required' => false],
            ],
        ];
    }
}
