<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Actions\ExecuteWarmDebugBuildAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\RunWarmDebugBuildJob;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The warm-build builder acquires its VPS slot before any agent work happens, so
 * a concurrency-cap failure means "nothing was spent, come back later" — the job
 * must re-dispatch, not flip the run to BuildingFailed.
 */
class RunWarmDebugBuildJobTransientTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);
    }

    private function buildingExperiment(int $transientRetries = 0): Experiment
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'track' => ExperimentTrack::Debug,
            'status' => ExperimentStatus::Building,
            'title' => 'Fix bug',
        ]);

        ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'stage' => StageType::Building,
            'status' => StageStatus::Running,
            'started_at' => now(),
            'output_snapshot' => $transientRetries > 0 ? ['_transient_retries' => $transientRetries] : [],
        ]);

        return $exp;
    }

    private function buildingStage(Experiment $exp): ExperimentStage
    {
        return ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $exp->id)
            ->where('stage', StageType::Building)
            ->latest()->firstOrFail();
    }

    public function test_transient_cap_re_dispatches_the_job(): void
    {
        Queue::fake();

        $action = Mockery::mock(ExecuteWarmDebugBuildAction::class);
        $action->shouldReceive('execute')->once()
            ->andThrow(VpsLocalAgentException::concurrencyCapReached(2));

        $exp = $this->buildingExperiment();
        (new RunWarmDebugBuildJob($exp->id, $exp->team_id))->handle($action);

        Queue::assertPushed(RunWarmDebugBuildJob::class);
        $this->assertSame(1, $this->buildingStage($exp)->output_snapshot['_transient_retries'] ?? null);
    }

    public function test_transient_cap_gives_up_after_budget_exhausted(): void
    {
        Queue::fake();
        config(['experiments.transient_capacity.max_retries' => 2]);

        $action = Mockery::mock(ExecuteWarmDebugBuildAction::class);
        $action->shouldReceive('execute')->once()
            ->andThrow(VpsLocalAgentException::concurrencyCapReached(2));
        $action->shouldReceive('failCapacityExhausted')->once();

        $exp = $this->buildingExperiment(transientRetries: 2);
        (new RunWarmDebugBuildJob($exp->id, $exp->team_id))->handle($action);

        Queue::assertNotPushed(RunWarmDebugBuildJob::class);
    }
}
