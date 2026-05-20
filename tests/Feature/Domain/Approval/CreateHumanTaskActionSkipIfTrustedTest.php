<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\CreateHumanTaskAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateHumanTaskActionSkipIfTrustedTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Experiment $experiment;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);

        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'status' => ExperimentStatus::Executing,
        ]);

        $this->workflow = Workflow::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'log-to-pr-test',
            'slug' => 'log-to-pr-test-'.uniqid(),
            'status' => WorkflowStatus::Draft,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_skip_if_trusted_disabled_creates_pending_approval(): void
    {
        $this->mockGraphExecutorExpectingNoExecute();

        $node = $this->createHumanTaskNode([
            'form_schema' => ['fields' => []],
            // skip_if_trusted not set → must be Pending
        ]);

        $step = $this->createWaitingStep($node);

        $approval = app(CreateHumanTaskAction::class)->execute($this->experiment, $step, $node);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame('waiting_human', $step->fresh()->status);
        $this->assertNull($approval->reviewed_at);
    }

    public function test_skip_if_trusted_with_high_confidence_auto_approves(): void
    {
        $this->mockGraphExecutorExpectingExecute();

        $upstreamNode = $this->createAgentNode();
        $upstreamStep = PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'workflow_node_id' => $upstreamNode->id,
            'order' => 1,
            'status' => 'completed',
            'output' => ['confidence' => 0.92, 'diff_summary' => 'fix typo'],
            'completed_at' => now(),
        ]);

        $node = $this->createHumanTaskNode([
            'form_schema' => ['fields' => []],
            'skip_if_trusted' => true,
            'confidence_threshold' => 0.85,
            'confidence_source_node_id' => $upstreamNode->id,
        ]);

        $step = $this->createWaitingStep($node);

        $approval = app(CreateHumanTaskAction::class)->execute($this->experiment, $step, $node);

        $this->assertSame(ApprovalStatus::Approved, $approval->status);
        $this->assertNotNull($approval->reviewed_at);
        $this->assertTrue($approval->context['auto_approved'] ?? false);
        $this->assertSame(0.92, $approval->context['confidence_observed']);

        $this->assertSame('completed', $step->fresh()->status);
        $this->assertTrue($step->fresh()->output['auto_approved'] ?? false);

        $this->assertDatabaseHas('audit_entries', [
            'event' => 'human_task.auto_approved',
            'team_id' => $this->team->id,
        ]);
    }

    public function test_skip_if_trusted_with_low_confidence_stays_pending(): void
    {
        $this->mockGraphExecutorExpectingNoExecute();

        $upstreamNode = $this->createAgentNode();
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'workflow_node_id' => $upstreamNode->id,
            'order' => 1,
            'status' => 'completed',
            'output' => ['confidence' => 0.40],
            'completed_at' => now(),
        ]);

        $node = $this->createHumanTaskNode([
            'skip_if_trusted' => true,
            'confidence_threshold' => 0.85,
            'confidence_source_node_id' => $upstreamNode->id,
        ]);

        $step = $this->createWaitingStep($node);

        $approval = app(CreateHumanTaskAction::class)->execute($this->experiment, $step, $node);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
        $this->assertSame('waiting_human', $step->fresh()->status);
    }

    public function test_skip_if_trusted_without_upstream_output_stays_pending(): void
    {
        $this->mockGraphExecutorExpectingNoExecute();

        $upstreamNode = $this->createAgentNode();
        // No PlaybookStep exists yet for the upstream node.

        $node = $this->createHumanTaskNode([
            'skip_if_trusted' => true,
            'confidence_threshold' => 0.85,
            'confidence_source_node_id' => $upstreamNode->id,
        ]);

        $step = $this->createWaitingStep($node);

        $approval = app(CreateHumanTaskAction::class)->execute($this->experiment, $step, $node);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
    }

    public function test_skip_if_trusted_with_invalid_threshold_stays_pending(): void
    {
        $this->mockGraphExecutorExpectingNoExecute();

        $upstreamNode = $this->createAgentNode();
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'workflow_node_id' => $upstreamNode->id,
            'order' => 1,
            'status' => 'completed',
            'output' => ['confidence' => 0.99],
            'completed_at' => now(),
        ]);

        $node = $this->createHumanTaskNode([
            'skip_if_trusted' => true,
            'confidence_threshold' => 1.5, // out of [0, 1] range — defensive guard
            'confidence_source_node_id' => $upstreamNode->id,
        ]);

        $step = $this->createWaitingStep($node);

        $approval = app(CreateHumanTaskAction::class)->execute($this->experiment, $step, $node);

        $this->assertSame(ApprovalStatus::Pending, $approval->status);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createHumanTaskNode(array $config = []): WorkflowNode
    {
        return WorkflowNode::create([
            'workflow_id' => $this->workflow->id,
            'type' => WorkflowNodeType::HumanTask,
            'label' => 'Test Human Task',
            'position_x' => 0,
            'position_y' => 0,
            'config' => $config,
            'order' => 2,
        ]);
    }

    private function createAgentNode(): WorkflowNode
    {
        return WorkflowNode::create([
            'workflow_id' => $this->workflow->id,
            'type' => WorkflowNodeType::Agent,
            'label' => 'Upstream Agent',
            'position_x' => 0,
            'position_y' => 0,
            'config' => [],
            'order' => 1,
        ]);
    }

    private function createWaitingStep(WorkflowNode $node): PlaybookStep
    {
        return PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'workflow_node_id' => $node->id,
            'order' => 3,
            'status' => 'pending',
        ]);
    }

    private function mockGraphExecutorExpectingExecute(): void
    {
        $this->mock(WorkflowGraphExecutor::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->once();
        });
    }

    private function mockGraphExecutorExpectingNoExecute(): void
    {
        $this->mock(WorkflowGraphExecutor::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('execute');
        });
    }
}
