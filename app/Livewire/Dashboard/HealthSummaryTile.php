<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Surfaces failure / stuck / circuit-breaker counters on the Dashboard so
 * customers don't have to navigate to /health to discover problems. The
 * tile is read-only — clicking "Triage" navigates to the Health page where
 * the existing retry / kill controls live.
 *
 * Counters are cached for 30s to avoid 4 COUNT queries per wire:poll cycle.
 * The cache key is team-scoped to prevent cross-tenant leaks.
 */
class HealthSummaryTile extends Component
{
    /** @var array{failed_24h: int, stuck_now: int, circuit_open: int, paused: int} */
    public array $counts = [
        'failed_24h' => 0,
        'stuck_now' => 0,
        'circuit_open' => 0,
        'paused' => 0,
    ];

    public function mount(): void
    {
        $this->refresh();
    }

    public function refresh(): void
    {
        $teamId = auth()->user()?->current_team_id;
        if ($teamId === null) {
            return;
        }

        $this->counts = Cache::remember(
            "dashboard.health_summary:{$teamId}",
            30,
            fn () => $this->computeCounts($teamId),
        );
    }

    public function render(): View
    {
        return view('livewire.dashboard.health-summary-tile');
    }

    /**
     * @return array{failed_24h: int, stuck_now: int, circuit_open: int, paused: int}
     */
    private function computeCounts(string $teamId): array
    {
        $failedStatuses = [
            ExperimentStatus::ScoringFailed,
            ExperimentStatus::PlanningFailed,
            ExperimentStatus::BuildingFailed,
            ExperimentStatus::ExecutionFailed,
        ];

        $failed24h = Experiment::where('team_id', $teamId)
            ->whereIn('status', $failedStatuses)
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        // Stuck now: any active state that hasn't had its updated_at touched
        // beyond the configured timeout. Default 15 min; matches the HealthPage
        // logic without re-importing the heavy collection-building method.
        $timeouts = config('experiments.recovery.timeouts', []);
        $activeStates = [
            ExperimentStatus::Scoring,
            ExperimentStatus::Planning,
            ExperimentStatus::Building,
            ExperimentStatus::Executing,
            ExperimentStatus::CollectingMetrics,
            ExperimentStatus::Evaluating,
        ];

        $stuckNow = 0;
        foreach ($activeStates as $state) {
            $cutoff = now()->subSeconds((int) ($timeouts[$state->value] ?? 900));
            $stuckNow += Experiment::where('team_id', $teamId)
                ->where('status', $state)
                ->where('updated_at', '<', $cutoff)
                ->count();
        }

        $circuitOpen = CircuitBreakerState::where('team_id', $teamId)
            ->whereIn('state', ['open', 'half_open'])
            ->count();

        $paused = Experiment::where('team_id', $teamId)
            ->where('status', ExperimentStatus::Paused)
            ->count();

        return [
            'failed_24h' => $failed24h,
            'stuck_now' => $stuckNow,
            'circuit_open' => $circuitOpen,
            'paused' => $paused,
        ];
    }
}
