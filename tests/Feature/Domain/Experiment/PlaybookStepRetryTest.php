<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Experiment\Services\CheckpointManager;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use App\Domain\Experiment\Services\WorkflowSnapshotRecorder;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaybookStepRetryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Experiment $experiment;

    private PlaybookStep $step;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test balance',
        ]);

        $agent = Agent::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Agent',
            'slug' => 'test-agent',
            'role' => 'assistant',
            'goal' => 'Test',
            'backstory' => 'Test',
            'status' => 'active',
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
        ]);

        $this->experiment = Experiment::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Experiment',
            'thesis' => 'Test thesis',
            'track' => ExperimentTrack::Workflow,
            'status' => ExperimentStatus::Executing,
            'constraints' => [],
        ]);

        $this->step = PlaybookStep::withoutGlobalScopes()->create([
            'experiment_id' => $this->experiment->id,
            'agent_id' => $agent->id,
            'order' => 1,
            'status' => 'pending',
            'input_mapping' => [],
        ]);

        // Mock infrastructure services that need Redis/broadcasting
        $checkpointManager = $this->mock(CheckpointManager::class);
        $checkpointManager->shouldReceive('generateIdempotencyKey')->andReturn('test-key');
        $checkpointManager->shouldReceive('getIdempotentResult')->andReturnNull();
        $checkpointManager->shouldReceive('writeCheckpoint')->andReturnNull();
        $checkpointManager->shouldReceive('startHeartbeat')->andReturn(fn () => null);
        $checkpointManager->shouldReceive('flushPendingCheckpoints')->andReturnNull();
        $checkpointManager->shouldReceive('clearCheckpoint')->andReturnNull();

        $broadcaster = $this->mock(StepOutputBroadcaster::class);
        $broadcaster->shouldReceive('broadcastNodeStatus')->andReturnNull();

        $snapshotRecorder = $this->mock(WorkflowSnapshotRecorder::class);
        $snapshotRecorder->shouldReceive('record')->andReturnNull();
    }

    public function test_step_is_not_marked_failed_when_retries_remain(): void
    {
        // Mock gateway to throw
        $gateway = $this->mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')
            ->andThrow(new \RuntimeException('Temporary API error'));

        $job = new ExecutePlaybookStepJob(
            stepId: $this->step->id,
            experimentId: $this->experiment->id,
            teamId: $this->team->id,
        );

        // Simulate first attempt (attempts() = 1, tries = 5, so retries remain)
        try {
            $job->handle(
                app(ExecuteAgentAction::class),
                app(ExecuteSkillAction::class),
            );
        } catch (\RuntimeException) {
            // Expected — job re-throws for Laravel to retry
        }

        // Step should be reset to pending, NOT failed
        $this->step->refresh();
        $this->assertEquals('pending', $this->step->status, 'Step should be pending after failed attempt with retries remaining');
    }

    public function test_step_is_marked_failed_by_failed_handler_after_retries_exhausted(): void
    {
        $job = new ExecutePlaybookStepJob(
            stepId: $this->step->id,
            experimentId: $this->experiment->id,
            teamId: $this->team->id,
        );

        // Use the failed() method — called by Laravel when all retries are exhausted
        $job->failed(new \RuntimeException('Permanent API error'));

        $this->step->refresh();
        $this->assertEquals('failed', $this->step->status, 'Step should be failed after all retries exhausted');
        $this->assertStringContainsString('Permanent API error', $this->step->error_message);
    }
}
