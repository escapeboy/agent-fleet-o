<?php

namespace App\Livewire\Metrics;

use App\Domain\AgentSession\Enums\AgentSessionEventKind;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * METR-style time-horizon dashboard for AgentSession runs.
 *
 * Aggregates `agent_sessions` and `agent_session_events` for the current team
 * over a selectable rolling window. Pure read — no writes, no state mutation.
 *
 * Inspired by METR's task-time-horizon graphs: how long do real agent runs
 * take, what fraction succeed, where does cost concentrate?
 */
class TimeHorizonPage extends Component
{
    public string $window = '7d';

    public function setWindow(string $window): void
    {
        $this->window = in_array($window, ['24h', '7d', '30d', 'all'], true) ? $window : '7d';
    }

    public function render()
    {
        $teamId = auth()->user()->current_team_id;
        $cutoff = $this->cutoffFor($this->window);

        /** @var Collection<int, AgentSession> $sessions */
        $sessions = AgentSession::query()
            ->where('team_id', $teamId)
            ->when($cutoff, fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->get();

        $totals = $this->computeSessionTotals($sessions);
        $eventStats = $this->computeEventStats($teamId, $cutoff);
        $perDay = $this->computeSessionsPerDay($teamId, $cutoff ?? Carbon::now()->subDays(28));

        return view('livewire.metrics.time-horizon-page', [
            'totals' => $totals,
            'eventStats' => $eventStats,
            'perDay' => $perDay,
        ])->layout('layouts.app', ['header' => 'Time-Horizon Metrics']);
    }

    private function cutoffFor(string $window): ?Carbon
    {
        return match ($window) {
            '24h' => Carbon::now()->subDay(),
            '7d' => Carbon::now()->subDays(7),
            '30d' => Carbon::now()->subDays(30),
            'all' => null,
            default => Carbon::now()->subDays(7),
        };
    }

    /**
     * @param  Collection<int, AgentSession>  $sessions
     * @return array{
     *   total: int,
     *   by_status: array<string, int>,
     *   completed_durations: array{count: int, avg: int|null, p50: int|null, p99: int|null},
     *   handoff_count: int,
     * }
     */
    private function computeSessionTotals(Collection $sessions): array
    {
        $byStatus = [];
        foreach (AgentSessionStatus::cases() as $case) {
            $byStatus[$case->value] = 0;
        }
        $completedDurations = [];
        foreach ($sessions as $session) {
            $key = $session->status->value;
            $byStatus[$key] = $byStatus[$key] + 1;
            if ($session->started_at && $session->ended_at) {
                $completedDurations[] = (int) $session->started_at->diffInSeconds($session->ended_at);
            }
        }

        sort($completedDurations);
        $count = count($completedDurations);
        $avg = $count > 0 ? (int) (array_sum($completedDurations) / $count) : null;
        $p50 = $count > 0 ? $completedDurations[(int) floor(($count - 1) * 0.50)] : null;
        $p99 = $count > 0 ? $completedDurations[(int) floor(($count - 1) * 0.99)] : null;

        $handoffCount = AgentSessionEvent::query()
            ->whereIn('session_id', $sessions->pluck('id'))
            ->whereIn('kind', [
                AgentSessionEventKind::HandoffOut->value,
                AgentSessionEventKind::HandoffIn->value,
            ])
            ->count();

        return [
            'total' => $sessions->count(),
            'by_status' => $byStatus,
            'completed_durations' => [
                'count' => $count,
                'avg' => $avg,
                'p50' => $p50,
                'p99' => $p99,
            ],
            'handoff_count' => $handoffCount,
        ];
    }

    /**
     * @return array{llm_total_tokens: int, llm_total_cost_usd: float, tool_call_count: int, tool_failure_count: int}
     */
    private function computeEventStats(string $teamId, ?Carbon $cutoff): array
    {
        $llmTokens = 0;
        $llmCost = 0.0;
        $toolCalls = 0;
        $toolFailures = 0;

        AgentSessionEvent::query()
            ->where('team_id', $teamId)
            ->when($cutoff, fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$llmTokens, &$llmCost, &$toolCalls, &$toolFailures) {
                foreach ($chunk as $event) {
                    /** @var AgentSessionEvent $event */
                    $payload = is_array($event->payload) ? $event->payload : [];

                    if ($event->kind === AgentSessionEventKind::LlmCall) {
                        $tokens = $payload['tokens_total'] ?? null;
                        if (is_int($tokens) || (is_string($tokens) && ctype_digit($tokens))) {
                            $llmTokens += (int) $tokens;
                        }
                        $cost = $payload['cost_usd'] ?? null;
                        if (is_numeric($cost)) {
                            $llmCost += (float) $cost;
                        }
                    }

                    if ($event->kind === AgentSessionEventKind::ToolCall) {
                        $toolCalls++;
                    }

                    if ($event->kind === AgentSessionEventKind::ToolResult) {
                        $error = $payload['error'] ?? null;
                        if ($error !== null && $error !== false && $error !== '' && $error !== 0) {
                            $toolFailures++;
                        }
                    }
                }
            });

        return [
            'llm_total_tokens' => $llmTokens,
            'llm_total_cost_usd' => $llmCost,
            'tool_call_count' => $toolCalls,
            'tool_failure_count' => $toolFailures,
        ];
    }

    /**
     * @return array<int, array{date: string, count: int}>
     */
    private function computeSessionsPerDay(string $teamId, Carbon $cutoff): array
    {
        $rows = DB::table('agent_sessions')
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $cutoff)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as cnt')
            ->groupByRaw('DATE(created_at)')
            ->orderBy('day')
            ->get();

        return $rows->map(fn ($r) => ['date' => (string) $r->day, 'count' => (int) $r->cnt])->all();
    }
}
