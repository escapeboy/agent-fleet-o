<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Observability;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\Observability\Alerts\AlertEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The stuck_experiments alert metric must count only running/pending stages
 * whose parent experiment is still active — orphaned rows left running on
 * terminal/failed/killed experiments must NOT inflate it (that class of row is
 * exactly what fired the false-positive PlatformAlert emails).
 */
class StuckExperimentsMetricTest extends TestCase
{
    use RefreshDatabase;

    private function stuckStageOn(ExperimentStatus $expStatus): void
    {
        $team = Team::factory()->create();
        $exp = Experiment::factory()->create(['team_id' => $team->id, 'status' => $expStatus]);
        $stage = ExperimentStage::factory()->create([
            'team_id' => $team->id,
            'experiment_id' => $exp->id,
            'stage' => StageType::Building,
            'status' => StageStatus::Running,
        ]);

        // Force updated_at older than the 15-min stuck window (bypass model timestamps).
        DB::table('experiment_stages')->where('id', $stage->id)
            ->update(['updated_at' => now()->subMinutes(20)]);
    }

    private function stuckCount(): int
    {
        $m = new ReflectionMethod(AlertEvaluator::class, 'stuckExperiments');
        $m->setAccessible(true);

        return (int) $m->invoke(app(AlertEvaluator::class));
    }

    public function test_orphaned_stages_on_terminal_or_failed_experiments_are_excluded(): void
    {
        $this->stuckStageOn(ExperimentStatus::BuildingFailed);
        $this->stuckStageOn(ExperimentStatus::Killed);
        $this->stuckStageOn(ExperimentStatus::Discarded);

        $this->assertSame(0, $this->stuckCount(), 'orphaned stages on non-active experiments must not count');
    }

    public function test_running_stage_on_active_experiment_still_counts(): void
    {
        $this->stuckStageOn(ExperimentStatus::Building);   // genuinely in-progress
        $this->stuckStageOn(ExperimentStatus::BuildingFailed); // orphan, must be ignored

        $this->assertSame(1, $this->stuckCount(), 'only the active-parent stage should count');
    }
}
