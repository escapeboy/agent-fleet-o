<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\ErrorMode\Models\ErrorMode;
use App\Domain\Evaluation\Actions\EvaluateDriftSignalsAction;
use App\Domain\Evaluation\Enums\DriftSignalType;
use App\Domain\Evaluation\Models\DriftSignal;
use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EvaluateDriftSignalsActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create(['name' => 'DR', 'slug' => 'dr-'.uniqid(), 'owner_id' => $user->id, 'settings' => []]);
        config([
            'evaluation.drift_monitor.window_hours' => 1,
            'evaluation.drift_monitor.baseline_hours' => 48,
            'evaluation.drift_monitor.notify_on_breach' => false,
            'evaluation.error_mode_catalog.enabled' => false,
        ]);
    }

    private function snapshot(float $avgScore, int $hoursAgo): void
    {
        $s = EvaluationMonitorSnapshot::create([
            'team_id' => $this->team->id, 'avg_score' => $avgScore, 'pass_rate' => 50,
            'active_count' => 1, 'deferred_passed' => 0, 'sampled_count' => 1,
        ]);
        DB::table('evaluation_monitor_snapshots')->where('id', $s->id)->update(['created_at' => now()->subHours($hoursAgo)]);
    }

    private function decaySignal(): DriftSignal
    {
        $signals = app(EvaluateDriftSignalsAction::class)->execute($this->team->id);

        return collect($signals)->firstWhere(fn ($s) => $s->signal_type === DriftSignalType::EvalScoreDecay);
    }

    public function test_eval_score_decay_breaches_when_recent_drops(): void
    {
        $this->snapshot(9.0, 10); // baseline window
        $this->snapshot(5.0, 0);  // recent window

        $signal = $this->decaySignal();
        $this->assertTrue($signal->breached);
        $this->assertEqualsWithDelta(5.0, $signal->value, 0.01);
        $this->assertEqualsWithDelta(9.0, $signal->baseline, 0.01);
    }

    public function test_eval_score_decay_no_breach_when_stable(): void
    {
        $this->snapshot(8.0, 10);
        $this->snapshot(8.0, 0);

        $this->assertFalse($this->decaySignal()->breached);
    }

    public function test_all_signals_skip_gracefully_without_data(): void
    {
        $signals = app(EvaluateDriftSignalsAction::class)->execute($this->team->id);

        $this->assertCount(4, $signals);
        foreach ($signals as $signal) {
            $this->assertFalse($signal->breached);
        }
        $byType = collect($signals)->keyBy(fn ($s) => $s->signal_type->value);
        $this->assertTrue($byType[DriftSignalType::ThumbsDownRate->value]->metadata['skipped']);
        $this->assertTrue($byType[DriftSignalType::LatencyCostSpike->value]->metadata['skipped']);
        $this->assertTrue($byType[DriftSignalType::InputDistributionShift->value]->metadata['skipped']);
    }

    public function test_breach_opens_error_mode_when_catalog_enabled(): void
    {
        config(['evaluation.error_mode_catalog.enabled' => true]);
        $this->snapshot(9.0, 10);
        $this->snapshot(4.0, 0);

        $this->decaySignal();

        $this->assertTrue(
            ErrorMode::where('team_id', $this->team->id)->where('name', 'drift:eval_score_decay')->exists(),
        );
    }
}
