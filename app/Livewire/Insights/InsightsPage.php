<?php

namespace App\Livewire\Insights;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class InsightsPage extends Component
{
    public function render()
    {
        $teamId = auth()->user()?->currentTeam?->id;

        $data = Cache::remember("insights.{$teamId}", 60, function () use ($teamId) {
            // Slowest pipeline stages (P95 duration in last 7 days)
            $slowestStages = ExperimentStage::whereNotNull('duration_ms')
                ->whereHas('experiment', fn ($q) => $q->where('team_id', $teamId))
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('stage, COUNT(*) as total_runs, AVG(duration_ms) as avg_ms, MAX(duration_ms) as max_ms, PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY duration_ms) as p95_ms')
                ->groupBy('stage')
                ->orderByDesc('p95_ms')
                ->limit(8)
                ->get();

            // Agent failure rates (last 7 days)
            $agentStats = AgentExecution::whereNotNull('agent_id')
                ->where('team_id', $teamId)
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('agent_id, COUNT(*) as total, SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed, AVG(duration_ms) as avg_ms, AVG(cost_credits) as avg_cost')
                ->groupBy('agent_id')
                ->orderByDesc('failed')
                ->limit(10)
                ->get()
                ->map(function ($row) {
                    $row->failure_rate = $row->total > 0 ? round(($row->failed / $row->total) * 100, 1) : 0;

                    return $row;
                });

            // Budget burn last 7 days per day
            $budgetBurn = CreditLedger::where('team_id', $teamId)
                ->where('type', 'deduction')
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('DATE(created_at) as day, SUM(amount) as credits')
                ->groupBy('day')
                ->orderBy('day')
                ->get();

            // Transition funnel — how many experiments reach each key state
            $funnelStates = ['scoring', 'planning', 'building', 'executing', 'completed'];
            $funnel = collect($funnelStates)->map(function ($state) use ($teamId) {
                return [
                    'state' => $state,
                    'count' => ExperimentStateTransition::whereHas('experiment', fn ($q) => $q->where('team_id', $teamId))
                        ->where('to_state', $state)
                        ->where('created_at', '>=', now()->subDays(30))
                        ->count(),
                ];
            });

            // Total experiments last 30d as funnel top
            $totalStarted = ExperimentStateTransition::whereHas('experiment', fn ($q) => $q->where('team_id', $teamId))
                ->where('to_state', 'scoring')
                ->where('created_at', '>=', now()->subDays(30))
                ->count();

            // Error clusters — state transitions going to failure states
            $failureTransitions = ExperimentStateTransition::whereHas('experiment', fn ($q) => $q->where('team_id', $teamId))
                ->whereIn('to_state', ['scoring_failed', 'planning_failed', 'building_failed', 'killed'])
                ->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('to_state, COUNT(*) as count')
                ->groupBy('to_state')
                ->orderByDesc('count')
                ->get();

            return compact('slowestStages', 'agentStats', 'budgetBurn', 'funnel', 'totalStarted', 'failureTransitions');
        });

        return view('livewire.insights.insights-page', $data)
            ->layout('layouts.app', ['header' => 'Workflow Insights']);
    }
}
