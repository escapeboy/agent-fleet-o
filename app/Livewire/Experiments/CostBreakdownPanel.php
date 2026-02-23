<?php

namespace App\Livewire\Experiments;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use Livewire\Component;

class CostBreakdownPanel extends Component
{
    public Experiment $experiment;

    public function render()
    {
        $runs = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $this->experiment->id)
            ->with('experimentStage:id,stage_type')
            ->orderBy('created_at')
            ->get();

        $totalCost = $runs->sum('cost_credits');
        $totalTokensIn = $runs->sum('input_tokens');
        $totalTokensOut = $runs->sum('output_tokens');
        $cachedRuns = $runs->where('cost_credits', 0)->where('status', 'completed');
        $cachedCount = $cachedRuns->count();

        // Estimate savings: average cost of non-cached runs * number of cached runs
        $nonCachedRuns = $runs->where('cost_credits', '>', 0);
        $avgCost = $nonCachedRuns->isNotEmpty()
            ? $nonCachedRuns->avg('cost_credits')
            : 0;
        $estimatedSavings = (int) round($cachedCount * $avgCost);

        // Cost breakdown by stage type
        $byStage = $runs
            ->filter(fn ($r) => $r->cost_credits > 0)
            ->groupBy(fn ($r) => $r->experimentStage?->stage_type ?? 'unknown')
            ->map(fn ($group) => [
                'runs' => $group->count(),
                'cost' => $group->sum('cost_credits'),
                'tokens_in' => $group->sum('input_tokens'),
                'tokens_out' => $group->sum('output_tokens'),
            ])
            ->sortByDesc('cost');

        // Cost breakdown by model
        $byModel = $runs
            ->filter(fn ($r) => $r->cost_credits > 0)
            ->groupBy(fn ($r) => "{$r->provider}/{$r->model}")
            ->map(fn ($group) => [
                'runs' => $group->count(),
                'cost' => $group->sum('cost_credits'),
                'tokens_in' => $group->sum('input_tokens'),
                'tokens_out' => $group->sum('output_tokens'),
                'avg_latency_ms' => (int) $group->avg('latency_ms'),
            ])
            ->sortByDesc('cost');

        return view('livewire.experiments.cost-breakdown-panel', [
            'runs' => $runs,
            'totalCost' => $totalCost,
            'totalTokensIn' => $totalTokensIn,
            'totalTokensOut' => $totalTokensOut,
            'cachedCount' => $cachedCount,
            'estimatedSavings' => $estimatedSavings,
            'byStage' => $byStage,
            'byModel' => $byModel,
        ]);
    }

    public function formatCredits(int $credits): string
    {
        if ($credits === 0) {
            return '0';
        }

        return number_format($credits);
    }

    public function formatTokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return number_format($tokens / 1_000_000, 1).'M';
        }

        if ($tokens >= 1000) {
            return number_format($tokens / 1000, 1).'K';
        }

        return (string) $tokens;
    }
}
