<?php

declare(strict_types=1);

namespace App\Livewire\Agents;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Compact SLA banner on AgentDetailPage. Computes per-agent
 * success_rate / latency_p95 / avg_cost / health_score from the last
 * 7 days of AgentExecution records.
 *
 * Health score is a simple 0-1 weighted blend (60% success rate +
 * 20% latency efficiency + 20% cost efficiency). The thresholds for
 * "efficient" are plan-agnostic heuristics; revisit when we have
 * baseline data.
 *
 * Cached for 5 min per (agent, team) to keep the agent-detail page
 * snappy without hitting the executions table on every poll.
 */
class AgentSlaPanel extends Component
{
    #[Locked]
    public string $agentId = '';

    /**
     * @var array{
     *   total_runs: int,
     *   success_rate: float|null,
     *   latency_p95_ms: int|null,
     *   avg_cost_credits: int|null,
     *   health_score: float|null,
     *   period_days: int,
     * }
     */
    public array $sla = [
        'total_runs' => 0,
        'success_rate' => null,
        'latency_p95_ms' => null,
        'avg_cost_credits' => null,
        'health_score' => null,
        'period_days' => 7,
    ];

    public function mount(Agent $agent): void
    {
        $this->agentId = $agent->id;
        $this->refresh();
    }

    public function refresh(): void
    {
        $teamId = auth()->user()?->current_team_id;
        if ($teamId === null) {
            return;
        }

        $this->sla = Cache::remember(
            "agent_sla:{$this->agentId}:{$teamId}",
            300,
            fn () => $this->compute($teamId),
        );
    }

    public function render(): View
    {
        return view('livewire.agents.agent-sla-panel');
    }

    /**
     * @return array{
     *   total_runs: int,
     *   success_rate: float|null,
     *   latency_p95_ms: int|null,
     *   avg_cost_credits: int|null,
     *   health_score: float|null,
     *   period_days: int,
     * }
     */
    private function compute(string $teamId): array
    {
        $cutoff = now()->subDays(7);

        $executions = AgentExecution::withoutGlobalScopes()
            ->where('agent_id', $this->agentId)
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $cutoff)
            ->get(['status', 'duration_ms', 'cost_credits']);

        $total = $executions->count();

        if ($total === 0) {
            return [
                'total_runs' => 0,
                'success_rate' => null,
                'latency_p95_ms' => null,
                'avg_cost_credits' => null,
                'health_score' => null,
                'period_days' => 7,
            ];
        }

        $successful = $executions->filter(fn ($e) => $e->status === 'success')->count();
        $successRate = round(($successful / $total) * 100, 1);

        $latencies = $executions->pluck('duration_ms')
            ->filter(fn ($v) => is_int($v) && $v > 0)
            ->sort()
            ->values();
        $latencyP95 = $latencies->isEmpty()
            ? null
            : (int) ($latencies[(int) floor(0.95 * ($latencies->count() - 1))] ?? $latencies->last());

        $costs = $executions->pluck('cost_credits')->filter(fn ($v) => is_int($v) && $v > 0);
        $avgCost = $costs->isEmpty() ? null : (int) round($costs->avg());

        $healthScore = $this->scoreHealth($successRate, $latencyP95, $avgCost);

        return [
            'total_runs' => $total,
            'success_rate' => $successRate,
            'latency_p95_ms' => $latencyP95,
            'avg_cost_credits' => $avgCost,
            'health_score' => $healthScore,
            'period_days' => 7,
        ];
    }

    /**
     * 0-1 health score blending success rate (60%), latency (20%), cost (20%).
     *
     * Latency: 100% under 5s, linearly degrading to 0% at 60s.
     * Cost: 100% under 50 credits, linearly degrading to 0% at 1000 credits.
     * These are P1 heuristic thresholds, revisit with real baselines.
     */
    private function scoreHealth(float $successPct, ?int $latencyP95Ms, ?int $avgCost): float
    {
        $successScore = $successPct / 100.0;

        $latencyScore = $latencyP95Ms === null
            ? 1.0
            : max(0.0, min(1.0, 1 - (($latencyP95Ms - 5000) / 55000)));

        $costScore = $avgCost === null
            ? 1.0
            : max(0.0, min(1.0, 1 - (($avgCost - 50) / 950)));

        $weighted = ($successScore * 0.6) + ($latencyScore * 0.2) + ($costScore * 0.2);

        return round($weighted, 2);
    }
}
