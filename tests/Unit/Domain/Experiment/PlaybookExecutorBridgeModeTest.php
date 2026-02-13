<?php

namespace Tests\Unit\Domain\Experiment;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExecutionMode;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Experiment\Pipeline\PlaybookExecutor;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PlaybookExecutorBridgeModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Team $team;
    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);

        $this->experiment = Experiment::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Experiment',
            'status' => ExperimentStatus::Executing,
            'track' => ExperimentTrack::Growth,
        ]);
    }

    /**
     * When bridge mode is active, parallel groups must be flattened
     * to sequential single-step groups so the single-threaded bridge
     * doesn't cause HTTP timeouts for queued requests.
     */
    public function test_parallel_steps_dispatched_sequentially_in_bridge_mode(): void
    {
        Bus::fake([ExecutePlaybookStepJob::class]);

        $groupId = fake()->uuid();

        // Create 3 parallel steps (same group_id)
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 0,
            'execution_mode' => ExecutionMode::Parallel,
            'group_id' => $groupId,
            'status' => 'pending',
        ]);
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 1,
            'execution_mode' => ExecutionMode::Parallel,
            'group_id' => $groupId,
            'status' => 'pending',
        ]);
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 2,
            'execution_mode' => ExecutionMode::Parallel,
            'group_id' => $groupId,
            'status' => 'pending',
        ]);

        // Mock LocalAgentDiscovery to report bridge mode is active
        $discovery = $this->mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(true);

        $executor = new PlaybookExecutor(
            app(TransitionExperimentAction::class),
            $discovery,
        );

        $executor->execute($this->experiment);

        // In bridge mode, only the FIRST step should be dispatched (as a single-step batch).
        // The remaining steps are dispatched one at a time via batch callbacks.
        Bus::assertBatchCount(1);
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 1);
    }

    /**
     * Without bridge mode, parallel steps are dispatched together in a single batch.
     */
    public function test_parallel_steps_dispatched_together_without_bridge(): void
    {
        Bus::fake([ExecutePlaybookStepJob::class]);

        $groupId = fake()->uuid();

        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 0,
            'execution_mode' => ExecutionMode::Parallel,
            'group_id' => $groupId,
            'status' => 'pending',
        ]);
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 1,
            'execution_mode' => ExecutionMode::Parallel,
            'group_id' => $groupId,
            'status' => 'pending',
        ]);
        PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 2,
            'execution_mode' => ExecutionMode::Parallel,
            'group_id' => $groupId,
            'status' => 'pending',
        ]);

        // Mock LocalAgentDiscovery to report bridge mode is NOT active
        $discovery = $this->mock(LocalAgentDiscovery::class);
        $discovery->shouldReceive('isBridgeMode')->andReturn(false);

        $executor = new PlaybookExecutor(
            app(TransitionExperimentAction::class),
            $discovery,
        );

        $executor->execute($this->experiment);

        // Without bridge mode, all 3 parallel steps are batched together
        Bus::assertBatchCount(1);
        Bus::assertBatched(fn ($batch) => $batch->jobs->count() === 3);
    }
}
