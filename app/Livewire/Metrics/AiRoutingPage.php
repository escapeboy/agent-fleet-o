<?php

namespace App\Livewire\Metrics;

use App\Domain\Agent\Models\AiRun;
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

        $stats = Cache::remember($cacheKey, 30, function () {
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

    private function getCostSavings($query): object
    {
        $actualCost = (int) $query->clone()->sum('cost_credits');

        // Find the most expensive model's output pricing as the "expensive tier" baseline
        $models = config('llm_pricing.models', config('llm_pricing.providers', []));
        $maxOutputRate = 0;
        foreach ($models as $providerModels) {
            foreach ($providerModels as $pricing) {
                if (isset($pricing['output']) && $pricing['output'] > $maxOutputRate) {
                    $maxOutputRate = $pricing['output'];
                }
            }
        }

        // Theoretical cost: re-price all tokens at the most expensive rate
        $tokenStats = $query->clone()->select(
            DB::raw('SUM(input_tokens) as total_input'),
            DB::raw('SUM(output_tokens) as total_output'),
        )->first();

        $maxInputRate = 0;
        foreach ($models as $providerModels) {
            foreach ($providerModels as $pricing) {
                if (isset($pricing['input']) && $pricing['input'] > $maxInputRate) {
                    $maxInputRate = $pricing['input'];
                }
            }
        }

        $theoreticalCost = 0;
        if ($tokenStats) {
            $theoreticalCost = (int) (
                (($tokenStats->total_input ?? 0) / 1000 * $maxInputRate) +
                (($tokenStats->total_output ?? 0) / 1000 * $maxOutputRate)
            );
        }

        $savings = $theoreticalCost > 0 ? round((1 - ($actualCost / $theoreticalCost)) * 100, 1) : 0;

        return (object) [
            'actual' => $actualCost,
            'theoretical' => $theoreticalCost,
            'savings_pct' => max(0, $savings),
        ];
    }

    private function getTopModels($query): array
    {
        return $query->select(
            DB::raw("CONCAT(provider, '/', model) as model_key"),
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
