<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Exceptions\UnstubbedNodeException;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class WorkflowSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    // ── Linear workflow ───────────────────────────────────────────────────────

    public function test_linear_workflow_executes_all_nodes(): void
    {
        [$workflow, $nodeIds] = $this->makeLinearWorkflow(['agent_a', 'agent_b']);

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['agent_a'], ['output' => 'a_result'])
            ->stub($nodeIds['agent_b'], ['output' => 'b_result'])
            ->run($workflow);

        $result->assertReached($nodeIds['agent_a']);
        $result->assertReached($nodeIds['agent_b']);
        $result->assertCompleted();
    }

    public function test_linear_workflow_preserves_execution_order(): void
    {
        [$workflow, $nodeIds] = $this->makeLinearWorkflow(['agent_a', 'agent_b', 'agent_c']);

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['agent_a'], [])
            ->stub($nodeIds['agent_b'], [])
            ->stub($nodeIds['agent_c'], [])
            ->run($workflow);

        $result->assertExecutionOrder([
            $nodeIds['agent_a'],
            $nodeIds['agent_b'],
            $nodeIds['agent_c'],
        ]);
    }

    public function test_stub_output_is_recorded_in_result(): void
    {
        [$workflow, $nodeIds] = $this->makeLinearWorkflow(['agent_a']);

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['agent_a'], ['score' => 0.95])
            ->run($workflow);

        $result->assertOutputEquals($nodeIds['agent_a'], ['score' => 0.95]);
    }

    // ── Unstubbed node ────────────────────────────────────────────────────────

    public function test_unstubbed_execution_node_throws(): void
    {
        [$workflow, $nodeIds] = $this->makeLinearWorkflow(['agent_a']);

        $this->expectException(UnstubbedNodeException::class);
        $this->expectExceptionMessageMatches('/agent_a/');

        (new WorkflowSimulator)->run($workflow);
    }

    // ── Conditional branching ─────────────────────────────────────────────────

    public function test_conditional_takes_true_branch(): void
    {
        [$workflow, $nodeIds] = $this->makeConditionalWorkflow(
            conditionConfig: ['field' => 'score', 'operator' => '>', 'value' => 0.5],
        );

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['scorer'], ['score' => 0.9])
            ->stub($nodeIds['true_branch'], ['branch' => 'true'])
            ->stub($nodeIds['false_branch'], ['branch' => 'false'])
            ->run($workflow);

        $result->assertReached($nodeIds['true_branch']);
        $result->assertNotReached($nodeIds['false_branch']);
    }

    public function test_conditional_takes_false_branch(): void
    {
        [$workflow, $nodeIds] = $this->makeConditionalWorkflow(
            conditionConfig: ['field' => 'score', 'operator' => '>', 'value' => 0.5],
        );

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['scorer'], ['score' => 0.1])
            ->stub($nodeIds['true_branch'], ['branch' => 'true'])
            ->stub($nodeIds['false_branch'], ['branch' => 'false'])
            ->run($workflow);

        $result->assertNotReached($nodeIds['true_branch']);
        $result->assertReached($nodeIds['false_branch']);
    }

    // ── Switch branching ──────────────────────────────────────────────────────

    public function test_switch_routes_to_matching_case(): void
    {
        [$workflow, $nodeIds] = $this->makeSwitchWorkflow(['urgent', 'normal', 'low']);

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['classifier'], ['priority' => 'urgent'])
            ->stub($nodeIds['urgent_handler'], ['handled' => true])
            ->stub($nodeIds['normal_handler'], ['handled' => true])
            ->stub($nodeIds['low_handler'], ['handled' => true])
            ->run($workflow);

        $result->assertReached($nodeIds['urgent_handler']);
        $result->assertNotReached($nodeIds['normal_handler']);
        $result->assertNotReached($nodeIds['low_handler']);
    }

    public function test_switch_falls_back_to_default_case(): void
    {
        [$workflow, $nodeIds] = $this->makeSwitchWorkflow(['urgent', 'normal', 'low']);

        // 'unknown' matches no case → should hit the default (first non-matched, or default edge)
        $result = (new WorkflowSimulator)
            ->stub($nodeIds['classifier'], ['priority' => 'unknown'])
            ->stub($nodeIds['urgent_handler'], ['handled' => true])
            ->stub($nodeIds['normal_handler'], ['handled' => true])
            ->stub($nodeIds['low_handler'], ['handled' => true])
            ->run($workflow);

        // Default branch is 'low_handler' (is_default=true in our helper)
        $result->assertNotReached($nodeIds['urgent_handler']);
        $result->assertNotReached($nodeIds['normal_handler']);
        $result->assertReached($nodeIds['low_handler']);
    }

    // ── Loop guard ────────────────────────────────────────────────────────────

    public function test_max_steps_guard_terminates_infinite_loop(): void
    {
        // Build a workflow where an agent loops back (start → agent → end, but no real loop possible in DAG)
        // We test maxSteps by using a very small limit on a large linear chain
        [$workflow, $nodeIds] = $this->makeLinearWorkflow(['a', 'b', 'c', 'd']);

        $result = (new WorkflowSimulator(maxSteps: 3))
            ->stub($nodeIds['a'], [])
            ->stub($nodeIds['b'], [])
            ->stub($nodeIds['c'], [])
            ->stub($nodeIds['d'], [])
            ->run($workflow);

        // With maxSteps=3, not all nodes complete
        $result->assertTerminatedWithStatus('loop_limit');
    }

    // ── assertNotReached failure message ──────────────────────────────────────

    public function test_assert_reached_fails_with_informative_message(): void
    {
        [$workflow, $nodeIds] = $this->makeLinearWorkflow(['agent_a']);

        $result = (new WorkflowSimulator)
            ->stub($nodeIds['agent_a'], [])
            ->run($workflow);

        $this->expectException(AssertionFailedError::class);
        $result->assertReached('non-existent-node-id');
    }

    // ── Stub chaining is immutable ─────────────────────────────────────────────

    public function test_stub_returns_new_instance(): void
    {
        $sim = new WorkflowSimulator;
        $sim2 = $sim->stub('some-id', []);

        $this->assertNotSame($sim, $sim2);
    }

    // ── Workflow with no nodes returns error status ────────────────────────────

    public function test_empty_workflow_returns_error_status(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $result = (new WorkflowSimulator)->run($workflow);

        $result->assertTerminatedWithStatus('error');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build: start → agent_labels[0] → agent_labels[1] → ... → end
     *
     * @param  list<string>  $labels
     * @return array{Workflow, array<string, string>} [workflow, node-label => node-id]
     */
    private function makeLinearWorkflow(array $labels): array
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $start = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Start,
            'label' => 'Start',
            'order' => 0,
        ]);

        $prev = $start;
        $nodeIds = [];
        $order = 1;

        foreach ($labels as $label) {
            $node = WorkflowNode::create([
                'workflow_id' => $workflow->id,
                'type' => WorkflowNodeType::Agent,
                'label' => $label,
                'order' => $order++,
            ]);
            WorkflowEdge::create([
                'workflow_id' => $workflow->id,
                'source_node_id' => $prev->id,
                'target_node_id' => $node->id,
            ]);
            $nodeIds[$label] = $node->id;
            $prev = $node;
        }

        $end = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::End,
            'label' => 'End',
            'order' => $order,
        ]);
        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $prev->id,
            'target_node_id' => $end->id,
        ]);

        return [$workflow, $nodeIds];
    }

    /**
     * Build:
     *   start → scorer → conditional → true_branch  → end
     *                             ↘ false_branch ↗
     *
     * @return array{Workflow, array<string, string>}
     */
    private function makeConditionalWorkflow(array $conditionConfig): array
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $start = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Start, 'label' => 'Start', 'order' => 0]);
        $scorer = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'scorer', 'order' => 1]);
        $cond = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Conditional, 'label' => 'Condition', 'order' => 2, 'config' => ['condition' => $conditionConfig]]);
        $trueBranch = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'true_branch', 'order' => 3]);
        $falseBranch = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'false_branch', 'order' => 4]);
        $end = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::End, 'label' => 'End', 'order' => 5]);

        // Edges
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $start->id, 'target_node_id' => $scorer->id]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $scorer->id, 'target_node_id' => $cond->id]);
        // True branch (non-default)
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $cond->id, 'target_node_id' => $trueBranch->id, 'is_default' => false]);
        // False branch (default)
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $cond->id, 'target_node_id' => $falseBranch->id, 'is_default' => true]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $trueBranch->id, 'target_node_id' => $end->id]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $falseBranch->id, 'target_node_id' => $end->id]);

        return [$workflow, [
            'scorer' => $scorer->id,
            'cond' => $cond->id,
            'true_branch' => $trueBranch->id,
            'false_branch' => $falseBranch->id,
        ]];
    }

    /**
     * Build switch workflow:
     *   start → classifier → switch → case_a_handler → end
     *                              ↘ case_b_handler ↗
     *                              ↘ default_handler ↗  (is_default=true)
     *
     * @param  list<string>  $cases  Last case becomes default
     * @return array{Workflow, array<string, string>}
     */
    private function makeSwitchWorkflow(array $cases): array
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $start = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Start, 'label' => 'Start', 'order' => 0]);
        $classifier = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'classifier', 'order' => 1]);
        $switch = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Switch, 'label' => 'Switch', 'order' => 2, 'config' => ['expression' => 'priority']]);
        $end = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::End, 'label' => 'End', 'order' => 100]);

        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $start->id, 'target_node_id' => $classifier->id]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $classifier->id, 'target_node_id' => $switch->id]);

        $nodeIds = ['classifier' => $classifier->id, 'switch' => $switch->id];
        $lastCase = array_pop($cases); // becomes default
        $order = 3;

        foreach ($cases as $case) {
            $handler = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => "{$case}_handler", 'order' => $order++]);
            WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $switch->id, 'target_node_id' => $handler->id, 'case_value' => $case, 'is_default' => false]);
            WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $handler->id, 'target_node_id' => $end->id]);
            $nodeIds["{$case}_handler"] = $handler->id;
        }

        // Default case
        $defaultHandler = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => "{$lastCase}_handler", 'order' => $order]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $switch->id, 'target_node_id' => $defaultHandler->id, 'is_default' => true]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $defaultHandler->id, 'target_node_id' => $end->id]);
        $nodeIds["{$lastCase}_handler"] = $defaultHandler->id;

        return [$workflow, $nodeIds];
    }
}
