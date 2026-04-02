<?php

namespace App\Domain\Experiment\Services;

use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StuckPattern;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Infrastructure\AI\Models\LlmRequestLog;
use Illuminate\Support\Facades\Log;

class StuckDetector
{
    private int $windowSize;

    private int $oscillationThreshold;

    private int $repeatedFailureThreshold;

    private float $toolLoopRepetitionRate;

    private float $stallMultiplier;

    private float $budgetDrainMultiplier;

    public function __construct()
    {
        $config = config('ai_routing.stuck_detection', []);

        $this->windowSize = (int) ($config['window_size'] ?? 10);
        $this->oscillationThreshold = (int) ($config['oscillation_threshold'] ?? 3);
        $this->repeatedFailureThreshold = (int) ($config['repeated_failure_threshold'] ?? 3);
        $this->toolLoopRepetitionRate = (float) ($config['tool_loop_repetition_rate'] ?? 0.70);
        $this->stallMultiplier = (float) ($config['stall_multiplier'] ?? 2.0);
        $this->budgetDrainMultiplier = (float) ($config['budget_drain_multiplier'] ?? 3.0);
    }

    /**
     * Analyze an experiment for stuck patterns. Returns the first detected pattern or null.
     */
    public function analyze(Experiment $experiment): ?StuckPattern
    {
        // Each check is ordered by severity (highest first) so the most critical
        // pattern is returned when multiple patterns are present.
        $checks = [
            fn () => $this->detectBudgetDrain($experiment),
            fn () => $this->detectToolCallLoop($experiment),
            fn () => $this->detectRepeatedStageFailure($experiment),
            fn () => $this->detectStateOscillation($experiment),
            fn () => $this->detectProgressStall($experiment),
        ];

        foreach ($checks as $check) {
            try {
                $result = $check();
                if ($result !== null) {
                    return $result;
                }
            } catch (\Throwable $e) {
                Log::debug('StuckDetector: check failed, skipping', [
                    'experiment_id' => $experiment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Get detection details for the last detected pattern (for event payload).
     */
    public function getDetails(Experiment $experiment, StuckPattern $pattern): array
    {
        return match ($pattern) {
            StuckPattern::StateOscillation => $this->getOscillationDetails($experiment),
            StuckPattern::RepeatedStageFailure => $this->getRepeatedFailureDetails($experiment),
            StuckPattern::ToolCallLoop => $this->getToolCallLoopDetails($experiment),
            StuckPattern::ProgressStall => $this->getProgressStallDetails($experiment),
            StuckPattern::BudgetDrain => $this->getBudgetDrainDetails($experiment),
        };
    }

    /**
     * Detect if the experiment bounces between 2 states more than the threshold within the window.
     */
    private function detectStateOscillation(Experiment $experiment): ?StuckPattern
    {
        $transitions = ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit($this->windowSize)
            ->pluck('to_state')
            ->toArray();

        if (count($transitions) < 4) {
            return null;
        }

        // Count transitions between each pair of states
        $pairCounts = [];
        for ($i = 0; $i < count($transitions) - 1; $i++) {
            $pair = $transitions[$i].'->'.$transitions[$i + 1];
            $pairCounts[$pair] = ($pairCounts[$pair] ?? 0) + 1;
        }

        // Check if any forward+reverse pair exceeds the threshold
        foreach ($pairCounts as $pair => $count) {
            [$from, $to] = explode('->', $pair);
            $reversePair = $to.'->'.$from;
            $reverseCount = $pairCounts[$reversePair] ?? 0;

            $oscillations = min($count, $reverseCount);
            if ($oscillations >= $this->oscillationThreshold) {
                return StuckPattern::StateOscillation;
            }
        }

        return null;
    }

    /**
     * Detect if the same stage type has failed consecutively beyond the threshold.
     */
    private function detectRepeatedStageFailure(Experiment $experiment): ?StuckPattern
    {
        $recentStages = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit($this->windowSize)
            ->get(['stage', 'status']);

        if ($recentStages->isEmpty()) {
            return null;
        }

        // Group consecutive failures by stage type
        $consecutiveFailures = 0;
        $lastStage = null;

        foreach ($recentStages as $stage) {
            if ($stage->status === StageStatus::Failed) {
                if ($lastStage === null || $stage->stage === $lastStage) {
                    $consecutiveFailures++;
                    $lastStage = $stage->stage;
                } else {
                    $consecutiveFailures = 1;
                    $lastStage = $stage->stage;
                }

                if ($consecutiveFailures >= $this->repeatedFailureThreshold) {
                    return StuckPattern::RepeatedStageFailure;
                }
            } else {
                $consecutiveFailures = 0;
                $lastStage = null;
            }
        }

        return null;
    }

    /**
     * Detect tool call loops by checking for repeated identical LLM requests (same prompt_hash).
     */
    private function detectToolCallLoop(Experiment $experiment): ?StuckPattern
    {
        $recentLogs = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderByDesc('created_at')
            ->limit(20)
            ->pluck('prompt_hash')
            ->filter()
            ->toArray();

        if (count($recentLogs) < 3) {
            return null;
        }

        // Calculate repetition rate: how many of the recent requests share the most common hash
        $hashCounts = array_count_values($recentLogs);
        $maxCount = max($hashCounts);
        $repetitionRate = $maxCount / count($recentLogs);

        if ($repetitionRate >= $this->toolLoopRepetitionRate) {
            return StuckPattern::ToolCallLoop;
        }

        return null;
    }

    /**
     * Detect if any stage has been running for longer than stall_multiplier * average duration.
     */
    private function detectProgressStall(Experiment $experiment): ?StuckPattern
    {
        $runningStage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('status', StageStatus::Running)
            ->whereNotNull('started_at')
            ->first(['stage', 'started_at']);

        if (! $runningStage) {
            return null;
        }

        $runningMinutes = $runningStage->started_at->diffInMinutes(now());

        // Get average duration for this stage type within the same team
        $avgDurationMs = ExperimentStage::withoutGlobalScopes()
            ->whereHas('experiment', fn ($q) => $q->withoutGlobalScopes()->where('team_id', $experiment->team_id))
            ->where('stage', $runningStage->stage)
            ->where('status', StageStatus::Completed)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        // Fallback: 10 minutes if no historical data
        $expectedMinutes = $avgDurationMs ? ($avgDurationMs / 60000) : 10.0;
        $threshold = $expectedMinutes * $this->stallMultiplier;

        if ($runningMinutes > $threshold) {
            return StuckPattern::ProgressStall;
        }

        return null;
    }

    /**
     * Detect if the experiment is consuming budget at an abnormally high rate.
     */
    private function detectBudgetDrain(Experiment $experiment): ?StuckPattern
    {
        // Get spend in the last 10 minutes
        $recentSpend = abs((int) CreditLedger::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('type', 'spend')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->sum('amount'));

        if ($recentSpend === 0) {
            return null;
        }

        // Get total spend and total runtime to compute average rate
        $totalSpend = abs((int) CreditLedger::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('type', 'spend')
            ->sum('amount'));

        $runtimeMinutes = max(1, $experiment->created_at->diffInMinutes(now()));
        $avgSpendPerMinute = $totalSpend / $runtimeMinutes;

        // Current rate (per minute over last 10 minutes)
        $currentRate = $recentSpend / 10;

        // Only flag if there's enough history to compare and the rate spike is real
        if ($avgSpendPerMinute > 0 && $currentRate > ($avgSpendPerMinute * $this->budgetDrainMultiplier)) {
            return StuckPattern::BudgetDrain;
        }

        return null;
    }

    // --- Detail methods for event payloads ---

    private function getOscillationDetails(Experiment $experiment): array
    {
        $transitions = ExperimentStateTransition::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->orderByDesc('created_at')
            ->limit($this->windowSize)
            ->get(['from_state', 'to_state', 'created_at'])
            ->toArray();

        return [
            'window_size' => $this->windowSize,
            'threshold' => $this->oscillationThreshold,
            'recent_transitions' => $transitions,
        ];
    }

    private function getRepeatedFailureDetails(Experiment $experiment): array
    {
        $failures = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('status', StageStatus::Failed)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['stage', 'status', 'output_snapshot', 'created_at'])
            ->toArray();

        return [
            'threshold' => $this->repeatedFailureThreshold,
            'recent_failures' => $failures,
        ];
    }

    private function getToolCallLoopDetails(Experiment $experiment): array
    {
        $recentLogs = LlmRequestLog::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['prompt_hash', 'model', 'cost_credits', 'created_at'])
            ->toArray();

        return [
            'threshold' => $this->toolLoopRepetitionRate,
            'recent_requests' => $recentLogs,
        ];
    }

    private function getProgressStallDetails(Experiment $experiment): array
    {
        $runningStage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('status', StageStatus::Running)
            ->first();

        return [
            'multiplier' => $this->stallMultiplier,
            'running_stage' => $runningStage?->stage?->value,
            'started_at' => $runningStage?->started_at?->toIso8601String(),
            'running_minutes' => $runningStage?->started_at?->diffInMinutes(now()),
        ];
    }

    private function getBudgetDrainDetails(Experiment $experiment): array
    {
        $recentSpend = abs((int) CreditLedger::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('type', 'spend')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->sum('amount'));

        $totalSpend = abs((int) CreditLedger::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('type', 'spend')
            ->sum('amount'));

        $runtimeMinutes = max(1, $experiment->created_at->diffInMinutes(now()));

        return [
            'multiplier' => $this->budgetDrainMultiplier,
            'recent_spend_10m' => $recentSpend,
            'total_spend' => $totalSpend,
            'avg_spend_per_minute' => round($totalSpend / $runtimeMinutes, 2),
            'current_rate_per_minute' => round($recentSpend / 10, 2),
        ];
    }
}
