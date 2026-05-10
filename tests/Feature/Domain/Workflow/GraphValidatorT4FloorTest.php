<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\GraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The T4 floor is the server-side invariant that PRs targeting a project's
 * promote_branch must always require human approval before auto-merge. This
 * test suite makes sure the validator rejects any graph that routes T4
 * directly to a Bitbucket merge node, even if the operator deliberately
 * edits the workflow to skip the human gate.
 */
class GraphValidatorT4FloorTest extends TestCase
{
    use RefreshDatabase;

    public function test_t4_branch_with_human_task_before_merge_is_valid(): void
    {
        $errors = $this->validateBugFixGraph(t4PathHasHumanTask: true);

        $t4Errors = array_filter($errors, fn ($e) => $e['type'] === 't4_floor_violation');
        $this->assertEmpty($t4Errors, 'T4 path with HumanTask should pass validation');
    }

    public function test_t4_branch_without_human_task_is_rejected(): void
    {
        $errors = $this->validateBugFixGraph(t4PathHasHumanTask: false);

        $t4Errors = array_filter($errors, fn ($e) => $e['type'] === 't4_floor_violation');
        $this->assertNotEmpty($t4Errors, 'T4 path without HumanTask must be rejected');
        $this->assertStringContainsString(
            'T4 floor',
            array_values($t4Errors)[0]['message'],
        );
    }

    public function test_validator_skips_when_no_bitbucket_merge_node_in_graph(): void
    {
        $workflow = Workflow::factory()->create();
        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $end->id,
        ]);

        $errors = app(GraphValidator::class)->validate($workflow);
        $t4Errors = array_filter($errors, fn ($e) => $e['type'] === 't4_floor_violation');

        $this->assertEmpty($t4Errors);
    }

    public function test_t4_case_value_match_is_case_insensitive(): void
    {
        // Build a graph where the conditional uses "t4" (lowercase) — should still trigger the invariant
        $workflow = Workflow::factory()->create();
        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $conditional = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Conditional,
            'label' => 'tier_route',
        ]);
        $merge = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::BitbucketPrMerge,
            'label' => 'merge',
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $conditional->id,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $conditional->id,
            'target_node_id' => $merge->id,
            'case_value' => 't4',
            'is_default' => false,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $conditional->id,
            'target_node_id' => $end->id,
            'is_default' => true,
        ]);
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $merge->id,
            'target_node_id' => $end->id,
        ]);

        $errors = app(GraphValidator::class)->validate($workflow);
        $t4Errors = array_filter($errors, fn ($e) => $e['type'] === 't4_floor_violation');

        $this->assertNotEmpty($t4Errors, 'Lowercase "t4" case_value must still trigger T4 floor');
    }

    /**
     * Build a minimal bug-fix-merge-style graph for T4 floor testing.
     *
     * Topology:
     *   start → conditional ──(case=T4)──→ [HumanTask?] → BitbucketPrMerge → end
     *                       └──(default)──────────────────────────────────→ end
     *
     * @return array<int, array<string, mixed>>
     */
    private function validateBugFixGraph(bool $t4PathHasHumanTask): array
    {
        $workflow = Workflow::factory()->create();
        $start = WorkflowNode::factory()->start()->create(['workflow_id' => $workflow->id]);
        $conditional = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Conditional,
            'label' => 'tier_route',
        ]);
        $merge = WorkflowNode::factory()->create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::BitbucketPrMerge,
            'label' => 'merge_pr',
        ]);
        $end = WorkflowNode::factory()->end()->create(['workflow_id' => $workflow->id]);

        // start → conditional
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $conditional->id,
        ]);

        $t4PathSource = $conditional->id;

        if ($t4PathHasHumanTask) {
            $humanTask = WorkflowNode::factory()->create([
                'workflow_id' => $workflow->id,
                'type' => WorkflowNodeType::HumanTask,
                'label' => 'human_review',
                'config' => ['form_schema' => ['fields' => []]],
            ]);

            // conditional ──(T4)──→ humanTask
            WorkflowEdge::factory()->create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $conditional->id,
                'target_node_id' => $humanTask->id,
                'case_value' => 'T4',
                'is_default' => false,
            ]);

            // humanTask → merge
            WorkflowEdge::factory()->create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $humanTask->id,
                'target_node_id' => $merge->id,
            ]);

            $t4PathSource = null; // already wired
        } else {
            // conditional ──(T4)──→ merge (DIRECT — invariant violation)
            WorkflowEdge::factory()->create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $conditional->id,
                'target_node_id' => $merge->id,
                'case_value' => 'T4',
                'is_default' => false,
            ]);
        }

        // conditional ──(default)──→ end
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $conditional->id,
            'target_node_id' => $end->id,
            'is_default' => true,
        ]);

        // merge → end
        WorkflowEdge::factory()->create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $merge->id,
            'target_node_id' => $end->id,
        ]);

        return app(GraphValidator::class)->validate($workflow);
    }
}
