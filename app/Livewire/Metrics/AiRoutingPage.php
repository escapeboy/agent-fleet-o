<?php

namespace App\Livewire\Metrics;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\Services\EvalShadowCounters;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class AiRoutingPage extends Component
{
    public string $timeWindow = '7d';

    public function updatedTimeWindow(): void
    {
        // Triggers re-render
    }

    public function render()
    {
        $teamId = auth()->user()->current_team_id;
        $cacheKey = "ai_routing.stats:{$teamId}:{$this->timeWindow}";

        $stats = Cache::remember($cacheKey, 30, function () use ($teamId) {
            $cutoff = match ($this->timeWindow) {
                '24h' => now()->subDay(),
                '7d' => now()->subWeek(),
                '30d' => now()->subMonth(),
                default => now()->subWeek(),
            };

            $query = AiRun::where('created_at', '>=', $cutoff);

            return [
                'tierDistribution' => $this->getTierDistribution($query->clone()),
                'budgetPressure' => $this->getBudgetPressureDistribution($query->clone()),
                'escalation' => $this->getEscalationStats($query->clone()),
                'verification' => $this->getVerificationStats($query->clone()),
                'costSavings' => $this->getCostSavings($query->clone()),
                'topModels' => $this->getTopModels($query->clone()),
                'totalRequests' => $query->clone()->count(),
                'evalShadow' => $this->getEvalShadow($teamId),
            ];
        });

        return view('livewire.metrics.ai-routing-page', $stats)
            ->layout('layouts.app', ['header' => 'AI Routing Analytics']);
    }

    private function getTierDistribution($query): array
    {
        $rows = $query->select(
            DB::raw("COALESCE(classified_complexity, 'unclassified') as tier"),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(cost_credits) as avg_cost'),
        )
            ->groupBy('tier')
            ->orderByDesc('count')
            ->get();

        $total = $rows->sum('count');

        return $rows->map(fn ($row) => (object) [
            'tier' => $row->tier,
            'count' => $row->count,
            'percentage' => $total > 0 ? round(($row->count / $total) * 100, 1) : 0,
            'avg_cost' => round($row->avg_cost, 1),
        ])->all();
    }

    private function getBudgetPressureDistribution($query): array
    {
        $rows = $query->select(
            DB::raw("COALESCE(budget_pressure_level, 'unclassified') as level"),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(cost_credits) as avg_cost'),
        )
            ->groupBy('level')
            ->orderByDesc('count')
            ->get();

        $total = $rows->sum('count');

        return $rows->map(fn ($row) => (object) [
            'level' => $row->level,
            'count' => $row->count,
            'percentage' => $total > 0 ? round(($row->count / $total) * 100, 1) : 0,
            'avg_cost' => round($row->avg_cost, 1),
        ])->all();
    }

    private function getEscalationStats($query): object
    {
        $total = $query->clone()->count();
        $escalated = $query->clone()->where('escalation_attempts', '>', 0)->count();
        $escalatedCompleted = $query->clone()
            ->where('escalation_attempts', '>', 0)
            ->where('status', 'completed')
            ->count();

        return (object) [
            'total' => $total,
            'escalated' => $escalated,
            'rate' => $total > 0 ? round(($escalated / $total) * 100, 1) : 0,
            'success_rate' => $escalated > 0 ? round(($escalatedCompleted / $escalated) * 100, 1) : 0,
        ];
    }

    private function getVerificationStats($query): object
    {
        $passed = $query->clone()->where('verification_passed', true)->count();
        $failed = $query->clone()->where('verification_passed', false)->count();
        $total = $passed + $failed;

        return (object) [
            'passed' => $passed,
            'failed' => $failed,
            'total' => $total,
            'catch_rate' => $total > 0 ? round(($failed / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Counterfactual "credits saved vs always-flagship": re-price the period's
     * actual token volume at the configured baseline (flagship) model and
     * compare to what was actually spent. Both sides are in credits (via
     * CostCalculator), so the figure is unit-coherent — the previous version
     * mixed credits with raw USD-per-token rates and always reported 0%.
     */
    private function getCostSavings($query): object
    {
        $actualCost = (int) $query->clone()->sum('cost_credits');

        $tokenStats = $query->clone()->select(
            DB::raw('COALESCE(SUM(input_tokens), 0) as total_input'),
            DB::raw('COALESCE(SUM(output_tokens), 0) as total_output'),
        )->first();

        $baseline = config('ai_routing.savings_baseline');
        $baselineModel = (string) ($baseline['model'] ?? '');

        $theoreticalCost = app(CostCalculator::class)->calculateCost(
            (string) ($baseline['provider'] ?? ''),
            $baselineModel,
            (int) ($tokenStats->total_input ?? 0),
            (int) ($tokenStats->total_output ?? 0),
        );

        $saved = max(0, $theoreticalCost - $actualCost);
        $savingsPct = $theoreticalCost > 0 ? round(($saved / $theoreticalCost) * 100, 1) : 0;

        return (object) [
            'actual' => $actualCost,
            'theoretical' => $theoreticalCost,
            'saved_credits' => $saved,
            'savings_pct' => $savingsPct,
            'baseline_model' => $baselineModel,
        ];
    }

    /**
     * Eval-grounded routing shadow telemetry (advisory only — never changes
     * live routing). Reads the rolling Redis counters the shadow middleware
     * writes; empty/zeroed when the feature is off or no traffic recorded.
     *
     * @return object{enabled: bool, total: int, would_downgrade: int, downgrade_pct: float, est_savings_credits: int}
     */
    private function getEvalShadow(string $teamId): object
    {
        $days = match ($this->timeWindow) {
            '24h' => 1,
            '30d' => 30,
            default => 7,
        };

        $totals = app(EvalShadowCounters::class)->totals($teamId, $days);

        $total = (int) $totals['total'];
        $downgrade = (int) $totals['would_downgrade'];

        return (object) [
            'enabled' => (bool) config('ai_routing.eval_grounded.enabled'),
            'total' => $total,
            'would_downgrade' => $downgrade,
            'downgrade_pct' => $total > 0 ? round(($downgrade / $total) * 100, 1) : 0,
            'est_savings_credits' => (int) $totals['est_savings_credits'],
        ];
    }

    private function getTopModels($query): array
    {
        return $query->select(
            DB::raw("(provider || '/' || model) as model_key"),
            DB::raw('COUNT(*) as requests'),
            DB::raw('AVG(latency_ms) as avg_latency'),
            DB::raw('AVG(cost_credits) as avg_cost'),
            DB::raw('SUM(cost_credits) as total_cost'),
        )
            ->groupBy('model_key')
            ->orderByDesc('requests')
            ->limit(15)
            ->get()
            ->map(fn ($row) => (object) [
                'model_key' => $row->model_key,
                'requests' => $row->requests,
                'avg_latency' => round($row->avg_latency),
                'avg_cost' => round($row->avg_cost, 1),
                'total_cost' => (int) $row->total_cost,
            ])
            ->all();
    }
}
