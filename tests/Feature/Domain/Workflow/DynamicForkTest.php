<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Listeners\ResumeParentOnSubWorkflowComplete;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\DynamicForkFanOutAction;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicForkTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Experiment $parentExperiment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
        $this->parentExperiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'workflow_id' => $workflow->id,
        ]);
    }

    // ── Inline mode ───────────────────────────────────────────────────────────

    public function test_inline_fork_injects_fork_items_into_template_step(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $resolveNode = new \ReflectionMethod($executor, 'resolveNode');

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $forkNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DynamicFork,
            'label' => 'Fork',
            'order' => 0,
            'config' => [
                'fork_source' => 'items',
                'fork_execution_mode' => 'inline',
                'fork_variable_name' => 'signal',
                'max_parallel_branches' => 10,
            ],
        ]);

        $templateNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Llm,
            'label' => 'Process Item',
            'order' => 1,
        ]);

        $predNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'Pred',
            'order' => 0,
        ]);

        // Create a predecessor step with output containing 'items'
        $predStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => $predNode->id,
            'order' => 0,
            'status' => 'completed',
            'output' => ['items' => ['a', 'b', 'c']],
        ]);

        $templateStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => $templateNode->id,
            'order' => 1,
            'status' => 'pending',
        ]);

        $nodeMap = [
            $forkNode->id => [
                'id' => $forkNode->id,
                'type' => 'dynamic_fork',
                'config' => [
                    'fork_source' => 'items',
                    'fork_execution_mode' => 'inline',
                    'fork_variable_name' => 'signal',
                    'max_parallel_branches' => 10,
                ],
            ],
            $templateNode->id => ['id' => $templateNode->id, 'type' => 'llm'],
            $predNode->id => ['id' => $predNode->id, 'type' => 'agent'],
        ];

        $edgeMap = [
            $forkNode->id => [
                ['source_node_id' => $forkNode->id, 'target_node_id' => $templateNode->id],
            ],
            $predNode->id => [
                ['source_node_id' => $predNode->id, 'target_node_id' => $forkNode->id],
            ],
        ];

        $adjacency = [
            $forkNode->id => [$templateNode->id],
            $predNode->id => [$forkNode->id],
        ];

        $steps = collect([
            $forkNode->id => null,
            $templateNode->id => $templateStep,
            $predNode->id => $predStep,
        ]);

        $executable = [];
        $visited = [];

        $resolveNode->invokeArgs($executor, [
            $forkNode->id, $nodeMap, $edgeMap, $adjacency,
            $steps, $this->parentExperiment, 10, &$executable, &$visited,
        ]);

        $this->assertContains($templateNode->id, $executable, 'Template node should be executable');

        $templateStep->refresh();
        $this->assertSame(['a', 'b', 'c'], $templateStep->input_mapping['_fork_items']);
        $this->assertSame('signal', $templateStep->input_mapping['_fork_variable']);
    }

    public function test_max_parallel_branches_caps_fork_items(): void
    {
        $executor = app(WorkflowGraphExecutor::class);
        $resolveNode = new \ReflectionMethod($executor, 'resolveNode');

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $forkNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::DynamicFork,
            'label' => 'Fork',
            'order' => 0,
            'config' => [
                'fork_source' => 'items',
                'max_parallel_branches' => 2,
            ],
        ]);

        $templateNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Llm,
            'label' => 'Process',
            'order' => 1,
        ]);

        $predNode = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'Pred',
            'order' => 0,
        ]);

        $predStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => $predNode->id,
            'order' => 0,
            'status' => 'completed',
            'output' => ['items' => ['a', 'b', 'c', 'd', 'e']],
        ]);

        $templateStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => $templateNode->id,
            'order' => 1,
            'status' => 'pending',
        ]);

        $nodeMap = [
            $forkNode->id => [
                'id' => $forkNode->id,
                'type' => 'dynamic_fork',
                'config' => ['fork_source' => 'items', 'max_parallel_branches' => 2],
            ],
            $templateNode->id => ['id' => $templateNode->id, 'type' => 'llm'],
            $predNode->id => ['id' => $predNode->id, 'type' => 'agent'],
        ];

        $edgeMap = [
            $forkNode->id => [['source_node_id' => $forkNode->id, 'target_node_id' => $templateNode->id]],
            $predNode->id => [['source_node_id' => $predNode->id, 'target_node_id' => $forkNode->id]],
        ];

        $adjacency = [$forkNode->id => [$templateNode->id], $predNode->id => [$forkNode->id]];
        $steps = collect([$forkNode->id => null, $templateNode->id => $templateStep, $predNode->id => $predStep]);

        $executable = [];
        $visited = [];

        $resolveNode->invokeArgs($executor, [
            $forkNode->id, $nodeMap, $edgeMap, $adjacency,
            $steps, $this->parentExperiment, 10, &$executable, &$visited,
        ]);

        $templateStep->refresh();
        $this->assertCount(2, $templateStep->input_mapping['_fork_items'], 'Should cap at max_parallel_branches=2');
    }

    // ── Fan-in (all N branches → merge node) ─────────────────────────────────

    public function test_fan_in_counter_increments_atomically(): void
    {
        $templateStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => null,
            'order' => 1,
            'status' => 'running',
            'output' => [
                'fork_children_total' => 3,
                'fork_children_done' => 0,
                'fork_children_ids' => ['exp-a', 'exp-b', 'exp-c'],
                'fork_results' => [],
            ],
        ]);

        $listener = app(ResumeParentOnSubWorkflowComplete::class);

        // Simulate first child completing
        $child1 = $this->makeChildExperiment($templateStep->id, index: 0);
        $this->fireTerminalEvent($listener, $child1, 'completed');

        $templateStep->refresh();
        $this->assertSame('running', $templateStep->status, 'Should still be running after 1/3');
        $this->assertSame(1, $templateStep->output['fork_children_done']);

        // Second child
        $child2 = $this->makeChildExperiment($templateStep->id, index: 1);
        $this->fireTerminalEvent($listener, $child2, 'completed');

        $templateStep->refresh();
        $this->assertSame('running', $templateStep->status, 'Should still be running after 2/3');

        // Third (last) child — mock continueAfterBatch so we don't need a full graph
        $this->mock(WorkflowGraphExecutor::class, function ($mock) {
            $mock->shouldReceive('continueAfterBatch')->once();
        });

        $child3 = $this->makeChildExperiment($templateStep->id, index: 2);
        $this->fireTerminalEvent($listener, $child3, 'completed');

        $templateStep->refresh();
        $this->assertSame('completed', $templateStep->status, 'Should complete after 3/3');
        $this->assertSame(3, $templateStep->output['fork_children_done']);
    }

    public function test_fan_in_counter_marks_failed_when_branch_fails(): void
    {
        $templateStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => null,
            'order' => 1,
            'status' => 'running',
            'output' => [
                'fork_children_total' => 2,
                'fork_children_done' => 1,
                'fork_results' => [],
            ],
        ]);

        $listener = app(ResumeParentOnSubWorkflowComplete::class);
        $child = $this->makeChildExperiment($templateStep->id, index: 1);
        $this->fireTerminalEvent($listener, $child, 'killed');

        $templateStep->refresh();
        $this->assertSame('failed', $templateStep->status);
    }

    // ── DynamicForkFanOutAction ───────────────────────────────────────────────

    public function test_fan_out_action_requires_sub_workflow_id(): void
    {
        $templateStep = PlaybookStep::create([
            'experiment_id' => $this->parentExperiment->id,
            'workflow_node_id' => null,
            'order' => 1,
            'status' => 'pending',
        ]);

        app(DynamicForkFanOutAction::class)->execute(
            step: $templateStep,
            parent: $this->parentExperiment,
            forkItems: ['a', 'b'],
            forkVariableName: 'item',
            nodeData: [], // no sub_workflow_id
        );

        $templateStep->refresh();
        $this->assertSame('failed', $templateStep->status);
        $this->assertStringContainsString('sub_workflow_id', $templateStep->error_message);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeChildExperiment(string $forkParentStepId, int $index): Experiment
    {
        return Experiment::factory()->create([
            'team_id' => $this->team->id,
            'parent_experiment_id' => $this->parentExperiment->id,
            'constraints' => [
                'fork_parent_step_id' => $forkParentStepId,
                'fork_parent_node_id' => null,
                'fork_item_index' => $index,
                'fork_total' => 3,
            ],
        ]);
    }

    private function fireTerminalEvent(
        ResumeParentOnSubWorkflowComplete $listener,
        Experiment $child,
        string $toStatusValue,
    ): void {
        $toState = ExperimentStatus::from($toStatusValue);

        $event = new ExperimentTransitioned(
            experiment: $child,
            fromState: ExperimentStatus::Executing,
            toState: $toState,
        );

        $listener->handle($event);
    }
}
