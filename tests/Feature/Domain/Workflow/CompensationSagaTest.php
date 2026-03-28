<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\RunCompensationChainAction;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Events\CompensationCompleted;
use App\Domain\Workflow\Events\CompensationStarted;
use App\Domain\Workflow\Jobs\ExecuteCompensationJob;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\GraphValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CompensationSagaTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    // ── RunCompensationChainAction ────────────────────────────────────────────

    public function test_compensation_runs_for_completed_steps_with_compensation_node(): void
    {
        Bus::fake();
        Event::fake([CompensationStarted::class, CompensationCompleted::class]);

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
        $agentNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Do Work', 'order' => 1]);
        $undoNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo Work', 'order' => 2]);

        $agentNode->update(['compensation_node_id' => $undoNode->id]);

        $experiment = $this->makeFailedExperiment($workflow);

        PlaybookStep::create([
            'experiment_id' => $experiment->id,
            'workflow_node_id' => $agentNode->id,
            'order' => 1,
            'status' => 'completed',
            'output' => ['result' => 'done'],
            'completed_at' => now(),
        ]);

        app(RunCompensationChainAction::class)->execute($experiment);

        Bus::assertDispatched(ExecuteCompensationJob::class, function ($job) use ($undoNode) {
            return $job->compensationNodeId === $undoNode->id;
        });

        Event::assertDispatched(CompensationStarted::class, fn ($e) => $e->totalCompensations === 1);
        Event::assertDispatched(CompensationCompleted::class, fn ($e) => $e->succeededCount === 1);
    }

    public function test_compensation_does_not_run_for_non_failed_experiments(): void
    {
        Bus::fake();

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
        $agentNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Do Work', 'order' => 1]);
        $undoNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo Work', 'order' => 2]);
        $agentNode->update(['compensation_node_id' => $undoNode->id]);

        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'workflow_id' => $workflow->id,
            'status' => ExperimentStatus::Completed,
        ]);

        PlaybookStep::create(['experiment_id' => $experiment->id, 'workflow_node_id' => $agentNode->id, 'order' => 1, 'status' => 'completed', 'completed_at' => now()]);

        app(RunCompensationChainAction::class)->execute($experiment);

        Bus::assertNotDispatched(ExecuteCompensationJob::class);
    }

    public function test_compensation_skips_steps_without_compensation_node(): void
    {
        Bus::fake();

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
        $agentNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'No Undo', 'order' => 1]);

        $experiment = $this->makeFailedExperiment($workflow);

        PlaybookStep::create(['experiment_id' => $experiment->id, 'workflow_node_id' => $agentNode->id, 'order' => 1, 'status' => 'completed', 'completed_at' => now()]);

        app(RunCompensationChainAction::class)->execute($experiment);

        Bus::assertNotDispatched(ExecuteCompensationJob::class);
    }

    public function test_compensation_does_not_run_for_pending_steps(): void
    {
        Bus::fake();

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
        $agentNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Pending', 'order' => 1]);
        $undoNode = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo', 'order' => 2]);
        $agentNode->update(['compensation_node_id' => $undoNode->id]);

        $experiment = $this->makeFailedExperiment($workflow);

        // Pending — should not trigger compensation
        PlaybookStep::create(['experiment_id' => $experiment->id, 'workflow_node_id' => $agentNode->id, 'order' => 1, 'status' => 'pending', 'completed_at' => null]);

        app(RunCompensationChainAction::class)->execute($experiment);

        Bus::assertNotDispatched(ExecuteCompensationJob::class);
    }

    public function test_multiple_compensation_steps_dispatched_for_multi_node_workflow(): void
    {
        Bus::fake();

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
        $nodeA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Step A', 'order' => 1]);
        $nodeB = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Step B', 'order' => 2]);
        $undoA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo A', 'order' => 3]);
        $undoB = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo B', 'order' => 4]);

        $nodeA->update(['compensation_node_id' => $undoA->id]);
        $nodeB->update(['compensation_node_id' => $undoB->id]);

        $experiment = $this->makeFailedExperiment($workflow);

        PlaybookStep::create(['experiment_id' => $experiment->id, 'workflow_node_id' => $nodeA->id, 'order' => 1, 'status' => 'completed', 'completed_at' => now()->subSeconds(10)]);
        PlaybookStep::create(['experiment_id' => $experiment->id, 'workflow_node_id' => $nodeB->id, 'order' => 2, 'status' => 'completed', 'completed_at' => now()]);

        app(RunCompensationChainAction::class)->execute($experiment);

        Bus::assertDispatchedTimes(ExecuteCompensationJob::class, 2);
    }

    // ── GraphValidator ────────────────────────────────────────────────────────

    public function test_graph_validator_rejects_recursive_compensation(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $start = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Start, 'label' => 'Start', 'order' => 0]);
        $nodeA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'A', 'order' => 1]);
        $undoA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo A', 'order' => 2]);
        $undoUndoA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo Undo A', 'order' => 3]);
        $end = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::End, 'label' => 'End', 'order' => 4]);

        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $start->id, 'target_node_id' => $nodeA->id]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $nodeA->id, 'target_node_id' => $end->id]);

        // undoA is compensation for nodeA, undoUndoA is compensation for undoA (recursive — invalid)
        $nodeA->update(['compensation_node_id' => $undoA->id]);
        $undoA->update(['compensation_node_id' => $undoUndoA->id]);

        $errors = app(GraphValidator::class)->validate($workflow);

        $errorTypes = array_column($errors, 'type');
        $this->assertContains('recursive_compensation', $errorTypes, 'Should reject recursive compensation chain');
    }

    public function test_graph_validator_accepts_valid_compensation_node(): void
    {
        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $start = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Start, 'label' => 'Start', 'order' => 0]);
        $nodeA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'A', 'order' => 1]);
        $undoA = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::Agent, 'label' => 'Undo A', 'order' => 2]);
        $end = WorkflowNode::create(['workflow_id' => $workflow->id, 'type' => WorkflowNodeType::End, 'label' => 'End', 'order' => 3]);

        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $start->id, 'target_node_id' => $nodeA->id]);
        WorkflowEdge::create(['workflow_id' => $workflow->id, 'source_node_id' => $nodeA->id, 'target_node_id' => $end->id]);

        $nodeA->update(['compensation_node_id' => $undoA->id]);
        // undoA has no compensation — valid

        $errors = app(GraphValidator::class)->validate($workflow);

        $compensationErrors = array_filter($errors, fn ($e) => str_contains($e['type'], 'compensation'));
        $this->assertEmpty($compensationErrors, 'Valid compensation chain should produce no errors');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFailedExperiment(Workflow $workflow): Experiment
    {
        return Experiment::factory()->create([
            'team_id' => $this->team->id,
            'workflow_id' => $workflow->id,
            'status' => ExperimentStatus::ExecutionFailed,
        ]);
    }
}
