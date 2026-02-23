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

    private function getModelStats($cutoff): Collection
    {
        // Gather stats from agent_executions (agent model is on the agent, not the execution)
        $agentStats = AgentExecution::withoutGlobalScopes()
            ->join('agents', 'agent_executions.agent_id', '=', 'agents.id')
            ->where('agent_executions.created_at', '>=', $cutoff)
            ->where('agent_executions.status', 'completed')
            ->select(
                DB::raw("CONCAT(agents.provider, '/', agents.model) as model_key"),
                DB::raw('COUNT(*) as exec_count'),
                DB::raw('AVG(agent_executions.quality_score) as avg_quality'),
                DB::raw('AVG(agent_executions.duration_ms) as avg_duration'),
                DB::raw('AVG(agent_executions.cost_credits) as avg_cost'),
                DB::raw('SUM(agent_executions.cost_credits) as total_cost'),
                DB::raw("COUNT(agent_executions.quality_score) as evaluated_count"),
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
            ->select(
                DB::raw("CONCAT(skills.configuration->>'provider', '/', skills.configuration->>'model') as model_key"),
                DB::raw('COUNT(*) as exec_count'),
                DB::raw('AVG(skill_executions.quality_score) as avg_quality'),
                DB::raw('AVG(skill_executions.duration_ms) as avg_duration'),
                DB::raw('AVG(skill_executions.cost_credits) as avg_cost'),
                DB::raw('SUM(skill_executions.cost_credits) as total_cost'),
                DB::raw("COUNT(skill_executions.quality_score) as evaluated_count"),
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
