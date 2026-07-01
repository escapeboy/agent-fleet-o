<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\RunBuildingStage;
use App\Domain\Experiment\Pipeline\RunWarmDebugBuildJob;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class RunBuildingStageDebugDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function debugExperimentInBuilding(bool $warmBuildAllowed = false): Experiment
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6), 'owner_id' => $user->id,
            'settings' => [], 'warm_build_allowed' => $warmBuildAllowed,
        ]);

        $exp = Experiment::factory()->create([
            'team_id' => $team->id,
            'track' => ExperimentTrack::Debug,
            'status' => ExperimentStatus::Building,
        ]);

        ExperimentStage::factory()->create([
            'team_id' => $team->id,
            'experiment_id' => $exp->id,
            'stage' => StageType::Building,
            'status' => StageStatus::Pending,
            'iteration' => $exp->current_iteration,
        ]);

        return $exp;
    }

    public function test_dispatches_warm_build_job_when_flag_and_team_allowed(): void
    {
        config(['experiments.warm_build.enabled' => true]);
        Bus::fake();
        $exp = $this->debugExperimentInBuilding(warmBuildAllowed: true);

        (new RunBuildingStage($exp->id, $exp->team_id))->handle();

        Bus::assertDispatched(RunWarmDebugBuildJob::class);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertSame('warm_build', $stage->output_snapshot['builder']);
    }

    public function test_does_not_dispatch_when_flag_disabled_bridge_path(): void
    {
        config(['experiments.warm_build.enabled' => false]);
        Bus::fake();
        $exp = $this->debugExperimentInBuilding(warmBuildAllowed: true);

        (new RunBuildingStage($exp->id, $exp->team_id))->handle();

        Bus::assertNotDispatched(RunWarmDebugBuildJob::class);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertSame('bridge', $stage->output_snapshot['builder']);
    }

    public function test_does_not_dispatch_when_flag_on_but_team_not_allowed(): void
    {
        config(['experiments.warm_build.enabled' => true]);
        Bus::fake();
        $exp = $this->debugExperimentInBuilding(warmBuildAllowed: false);

        (new RunBuildingStage($exp->id, $exp->team_id))->handle();

        Bus::assertNotDispatched(RunWarmDebugBuildJob::class);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertSame('bridge', $stage->output_snapshot['builder']);
    }

    /**
     * Regression: re-entering the building stage after a debug-track dispatch
     * (e.g. a duplicate ExperimentTransitioned or a manual retry) must be an
     * idempotent skip, not a crash. Debug-track stages carry `debug_track` but
     * never a `batch_id`; a raw read of the missing key promotes to an
     * ErrorException at runtime and flips the experiment to building_failed.
     */
    public function test_reentry_on_debug_track_stage_skips_without_crashing(): void
    {
        config(['experiments.warm_build.enabled' => false]);
        Bus::fake();
        $exp = $this->debugExperimentInBuilding();

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $stage->update([
            'status' => StageStatus::Running,
            'output_snapshot' => ['debug_track' => true, 'builder' => 'bridge'],
        ]);

        // Mirror Laravel's runtime HandleExceptions: warnings ("Undefined array
        // key batch_id") become ErrorExceptions, which is what fails the build.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            (new RunBuildingStage($exp->id, $exp->team_id))->handle();
        } finally {
            restore_error_handler();
        }

        $stage->refresh();
        $this->assertSame(StageStatus::Running, $stage->status);
    }
}
