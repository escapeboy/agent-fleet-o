<?php

namespace App\Livewire\Metrics;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ModelComparisonPage extends Component
{
    public string $timeWindow = '7d';

    public string $sortBy = 'total_executions';

    public string $sortDir = 'desc';

    /**
     * Aggregation scope. 'team' (default) restricts the comparison to the
     * current team's executions; 'platform' aggregates across every team and
     * is honoured only for super-admins (enforced in {@see getModelStats()}).
     */
    public string $scope = 'team';

    public function updatedTimeWindow(): void
    {
        // Triggers re-render
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function render()
    {
        $cutoff = match ($this->timeWindow) {
            '24h' => now()->subDay(),
            '7d' => now()->subWeek(),
            '30d' => now()->subMonth(),
            default => now()->subWeek(),
        };

        $models = $this->getModelStats($cutoff);

        return view('livewire.metrics.model-comparison-page', [
            'models' => $models,
        ])->layout('layouts.app', ['header' => 'Model Comparison']);
    }

    /**
     * Whether platform-wide aggregation is in effect — true only when a
     * super-admin viewer has explicitly switched the scope to 'platform'.
     * Any other caller (including a crafted scope=platform request) is false.
     */
    private function platformScopeActive(): bool
    {
        return $this->scope === 'platform'
            && (bool) (auth()->user()->is_super_admin ?? false);
    }

    private function getModelStats($cutoff): Collection
    {
        // Team scoping. `withoutGlobalScopes()` is kept deliberately: re-applying
        // TeamScope would emit an unqualified `team_id` that collides with the
        // joined agents/skills tables. Instead the team filter is applied
        // explicitly and table-qualified below. $teamId is null ONLY for a
        // super-admin in platform scope — every other viewer is pinned to their
        // own team, so a crafted scope=platform cannot widen the result set.
        $teamId = $this->platformScopeActive()
            ? null
            : auth()->user()->currentTeam->id;

        // Gather stats from agent_executions (agent model is on the agent, not the execution)
        $agentStats = AgentExecution::withoutGlobalScopes()
            ->join('agents', 'agent_executions.agent_id', '=', 'agents.id')
            ->where('agent_executions.created_at', '>=', $cutoff)
            ->where('agent_executions.status', 'completed')
            ->when($teamId, fn ($q) => $q->where('agent_executions.team_id', $teamId))
            ->select(
                DB::raw("CONCAT(agents.provider, '/', agents.model) as model_key"),
                DB::raw('COUNT(*) as exec_count'),
                DB::raw('AVG(agent_executions.quality_score) as avg_quality'),
                DB::raw('AVG(agent_executions.duration_ms) as avg_duration'),
                DB::raw('AVG(agent_executions.cost_credits) as avg_cost'),
                DB::raw('SUM(agent_executions.cost_credits) as total_cost'),
                DB::raw('COUNT(agent_executions.quality_score) as evaluated_count'),
            )
            ->groupBy('model_key')
            ->get()
            ->keyBy('model_key');

        // Gather stats from skill_executions (skill model is in skill configuration JSONB)
        $skillStats = SkillExecution::withoutGlobalScopes()
            ->join('skills', 'skill_executions.skill_id', '=', 'skills.id')
            ->where('skill_executions.created_at', '>=', $cutoff)
            ->where('skill_executions.status', 'completed')
            ->whereNotNull('skills.configuration')
            ->when($teamId, fn ($q) => $q->where('skill_executions.team_id', $teamId))
            ->select(
                DB::raw("CONCAT(skills.configuration->>'provider', '/', skills.configuration->>'model') as model_key"),
                DB::raw('COUNT(*) as exec_count'),
                DB::raw('AVG(skill_executions.quality_score) as avg_quality'),
                DB::raw('AVG(skill_executions.duration_ms) as avg_duration'),
                DB::raw('AVG(skill_executions.cost_credits) as avg_cost'),
                DB::raw('SUM(skill_executions.cost_credits) as total_cost'),
                DB::raw('COUNT(skill_executions.quality_score) as evaluated_count'),
            )
            ->groupBy('model_key')
            ->get()
            ->keyBy('model_key');

        // Merge stats by model
        $allKeys = $agentStats->keys()->merge($skillStats->keys())->unique();

        $merged = $allKeys->map(function ($key) use ($agentStats, $skillStats) {
            $agent = $agentStats->get($key);
            $skill = $skillStats->get($key);

            $totalExec = ($agent->exec_count ?? 0) + ($skill->exec_count ?? 0);
            $totalEvaluated = ($agent->evaluated_count ?? 0) + ($skill->evaluated_count ?? 0);

            // Weighted average for quality and duration
            $avgQuality = null;
            if ($totalEvaluated > 0) {
                $avgQuality = (
                    (($agent->avg_quality ?? 0) * ($agent->evaluated_count ?? 0)) +
                    (($skill->avg_quality ?? 0) * ($skill->evaluated_count ?? 0))
                ) / $totalEvaluated;
            }

            $avgDuration = $totalExec > 0
                ? ((($agent->avg_duration ?? 0) * ($agent->exec_count ?? 0)) +
                   (($skill->avg_duration ?? 0) * ($skill->exec_count ?? 0))) / $totalExec
                : 0;

            $avgCost = $totalExec > 0
                ? ((($agent->avg_cost ?? 0) * ($agent->exec_count ?? 0)) +
                   (($skill->avg_cost ?? 0) * ($skill->exec_count ?? 0))) / $totalExec
                : 0;

            return (object) [
                'model_key' => $key,
                'total_executions' => $totalExec,
                'evaluated_count' => $totalEvaluated,
                'avg_quality' => $avgQuality,
                'avg_duration_ms' => round($avgDuration),
                'avg_cost_credits' => round($avgCost, 1),
                'total_cost' => ($agent->total_cost ?? 0) + ($skill->total_cost ?? 0),
            ];
        })->filter(fn ($m) => $m->model_key && $m->model_key !== '/');

        // Sort
        return $merged->sortBy(
            fn ($m) => $m->{$this->sortBy} ?? 0,
            SORT_NUMERIC,
            $this->sortDir === 'desc',
        )->values();
    }
}
