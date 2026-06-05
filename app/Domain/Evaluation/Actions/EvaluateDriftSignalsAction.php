<?php

namespace App\Domain\Evaluation\Actions;

use App\Domain\ErrorMode\Actions\RecordErrorModeOccurrenceAction;
use App\Domain\Evaluation\Enums\DriftSignalType;
use App\Domain\Evaluation\Models\DriftSignal;
use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use App\Domain\Shared\Services\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Agentic AI Flywheel #4 — compute the four drift signals over a recent window
 * vs a trailing baseline, persist each observation, and (optionally) alert and
 * re-open the loop on breach. Each signal degrades gracefully to a skipped
 * observation when its source data is unavailable (honest no-op, not a fake zero).
 */
final class EvaluateDriftSignalsAction
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly RecordErrorModeOccurrenceAction $recordErrorMode,
    ) {}

    /**
     * @return list<DriftSignal>
     */
    public function execute(string $teamId): array
    {
        $windowHours = max(1, (int) config('evaluation.drift_monitor.window_hours', 24));
        $baselineHours = max($windowHours + 1, (int) config('evaluation.drift_monitor.baseline_hours', 168));
        $thresholds = (array) config('evaluation.drift_monitor.thresholds', []);

        $now = now();
        $recentFrom = $now->copy()->subHours($windowHours);
        $baselineFrom = $now->copy()->subHours($baselineHours);
        $window = $windowHours.'h';

        $signals = [];
        $signals[] = $this->evalScoreDecay($teamId, $recentFrom, $baselineFrom, $window, (float) ($thresholds['eval_score_decay'] ?? 1.0));
        $signals[] = $this->thumbsDownRate($teamId, $recentFrom, $window, (float) ($thresholds['thumbs_down_rate'] ?? 0.15));
        $signals[] = $this->latencyCostSpike($teamId, $recentFrom, $baselineFrom, $window, (float) ($thresholds['latency_p95_mult'] ?? 1.5), (float) ($thresholds['cost_avg_mult'] ?? 1.5));
        $signals[] = $this->inputDistributionShift($teamId, $window);

        return $signals;
    }

    private function evalScoreDecay(string $teamId, CarbonInterface $recentFrom, CarbonInterface $baselineFrom, string $window, float $threshold): DriftSignal
    {
        $recent = EvaluationMonitorSnapshot::query()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $recentFrom)
            ->whereNotNull('avg_score')
            ->avg('avg_score');

        $baseline = EvaluationMonitorSnapshot::query()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $baselineFrom)
            ->where('created_at', '<', $recentFrom)
            ->whereNotNull('avg_score')
            ->avg('avg_score');

        if ($recent === null || $baseline === null) {
            return $this->record($teamId, DriftSignalType::EvalScoreDecay, null, $baseline !== null ? (float) $baseline : null, false, $window, ['skipped' => true, 'reason' => 'insufficient_snapshots']);
        }

        $drop = (float) $baseline - (float) $recent;
        $breached = $drop > $threshold;

        return $this->record($teamId, DriftSignalType::EvalScoreDecay, (float) $recent, (float) $baseline, $breached, $window, ['drop' => round($drop, 2), 'threshold' => $threshold]);
    }

    private function thumbsDownRate(string $teamId, CarbonInterface $recentFrom, string $window, float $threshold): DriftSignal
    {
        $total = DB::table('chatbot_messages')
            ->where('team_id', $teamId)
            ->where('role', 'assistant')
            ->where('created_at', '>=', $recentFrom)
            ->count();

        if ($total === 0) {
            return $this->record($teamId, DriftSignalType::ThumbsDownRate, null, null, false, $window, ['skipped' => true, 'reason' => 'no_assistant_messages']);
        }

        $down = DB::table('chatbot_messages')
            ->where('team_id', $teamId)
            ->where('role', 'assistant')
            ->where('feedback', 'thumbs_down')
            ->where('created_at', '>=', $recentFrom)
            ->count();

        $rate = $down / $total;
        $breached = $rate > $threshold;

        return $this->record($teamId, DriftSignalType::ThumbsDownRate, round($rate, 4), $threshold, $breached, $window, ['thumbs_down' => $down, 'total' => $total]);
    }

    private function latencyCostSpike(string $teamId, CarbonInterface $recentFrom, CarbonInterface $baselineFrom, string $window, float $latencyMult, float $costMult): DriftSignal
    {
        $recentLatencies = DB::table('llm_request_logs')
            ->where('team_id', $teamId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $recentFrom)
            ->pluck('latency_ms')
            ->map(fn ($v) => (float) $v)
            ->all();

        $baselineLatencies = DB::table('llm_request_logs')
            ->where('team_id', $teamId)
            ->where('status', 'completed')
            ->where('created_at', '>=', $baselineFrom)
            ->where('created_at', '<', $recentFrom)
            ->pluck('latency_ms')
            ->map(fn ($v) => (float) $v)
            ->all();

        $recentP95 = $this->percentile($recentLatencies, 0.95);
        $baselineP95 = $this->percentile($baselineLatencies, 0.95);

        if ($recentP95 === null || $baselineP95 === null || $baselineP95 <= 0.0) {
            return $this->record($teamId, DriftSignalType::LatencyCostSpike, $recentP95, $baselineP95, false, $window, ['skipped' => true, 'reason' => 'insufficient_request_logs']);
        }

        $recentCost = DB::table('llm_request_logs')->where('team_id', $teamId)->where('status', 'completed')->where('created_at', '>=', $recentFrom)->avg('cost_credits');
        $baselineCost = DB::table('llm_request_logs')->where('team_id', $teamId)->where('status', 'completed')->where('created_at', '>=', $baselineFrom)->where('created_at', '<', $recentFrom)->avg('cost_credits');

        $latencyBreach = $recentP95 > $baselineP95 * $latencyMult;
        $costBreach = $baselineCost !== null && (float) $baselineCost > 0.0 && (float) $recentCost > (float) $baselineCost * $costMult;

        return $this->record($teamId, DriftSignalType::LatencyCostSpike, $recentP95, $baselineP95, $latencyBreach || $costBreach, $window, [
            'recent_p95_ms' => round($recentP95, 1),
            'baseline_p95_ms' => round($baselineP95, 1),
            'recent_avg_cost' => $recentCost !== null ? round((float) $recentCost, 2) : null,
            'baseline_avg_cost' => $baselineCost !== null ? round((float) $baselineCost, 2) : null,
            'latency_breach' => $latencyBreach,
            'cost_breach' => $costBreach,
        ]);
    }

    private function inputDistributionShift(string $teamId, string $window): DriftSignal
    {
        // Embedding-based novelty detection on incoming signals is future work
        // (no per-signal embedding store yet). Persist an honest skipped observation.
        return $this->record($teamId, DriftSignalType::InputDistributionShift, null, null, false, $window, ['skipped' => true, 'reason' => 'embeddings_unavailable']);
    }

    /**
     * @param  float[]  $values
     */
    private function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $index = (int) ceil($p * count($values)) - 1;
        $index = max(0, min(count($values) - 1, $index));

        return (float) $values[$index];
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function record(string $teamId, DriftSignalType $type, ?float $value, ?float $baseline, bool $breached, string $window, array $metadata): DriftSignal
    {
        $signal = DriftSignal::create([
            'team_id' => $teamId,
            'signal_type' => $type,
            'value' => $value,
            'baseline' => $baseline,
            'breached' => $breached,
            'window' => $window,
            'detected_at' => now(),
            'metadata' => $metadata,
        ]);

        if ($breached) {
            if (config('evaluation.drift_monitor.notify_on_breach', false)) {
                $this->notifications->notifyTeam(
                    teamId: $teamId,
                    type: 'drift_alert',
                    title: 'Drift detected: '.$type->label(),
                    body: sprintf('%s breached (value %s, baseline %s) over the last %s.', $type->label(), $value ?? 'n/a', $baseline ?? 'n/a', $window),
                    actionUrl: null,
                    data: ['signal_type' => $type->value, 'metadata' => $metadata],
                );
            }

            if (config('evaluation.error_mode_catalog.enabled', false)) {
                $this->recordErrorMode->execute($teamId, 'drift:'.$type->value, null, ['signal' => $type->value]);
            }
        }

        return $signal;
    }
}
