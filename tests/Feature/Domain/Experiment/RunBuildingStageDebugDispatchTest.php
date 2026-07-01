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

    private function debugExperimentInBuilding(): Experiment
    {
        $user = User::factory()->create();
        $team = Team::create(['name' => 'T', 'slug' => 't-'.Str::random(6), 'owner_id' => $user->id, 'settings' => []]);

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

    public function test_dispatches_warm_build_job_when_flag_enabled(): void
    {
        config(['experiments.warm_build.enabled' => true]);
        Bus::fake();
        $exp = $this->debugExperimentInBuilding();

        (new RunBuildingStage($exp->id, $exp->team_id))->handle();

        Bus::assertDispatched(RunWarmDebugBuildJob::class);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertSame('warm_build', $stage->output_snapshot['builder']);
    }

    public function test_does_not_dispatch_when_flag_disabled_bridge_path(): void
    {
        config(['experiments.warm_build.enabled' => false]);
        Bus::fake();
        $exp = $this->debugExperimentInBuilding();

        (new RunBuildingStage($exp->id, $exp->team_id))->handle();

        Bus::assertNotDispatched(RunWarmDebugBuildJob::class);

        $stage = ExperimentStage::where('experiment_id', $exp->id)->where('stage', StageType::Building)->first();
        $this->assertSame('bridge', $stage->output_snapshot['builder']);
    }
}
