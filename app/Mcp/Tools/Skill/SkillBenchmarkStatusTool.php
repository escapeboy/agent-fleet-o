<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Enums\IterationOutcome;
use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillBenchmarkStatusTool extends Tool
{
    protected string $name = 'skill_benchmark_status';

    protected string $description = 'Get current status, iteration progress, and best metric value for a running or completed skill benchmark.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'benchmark_id' => $schema->string()
                ->description('The benchmark UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['benchmark_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $benchmark = SkillBenchmark::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with(['skill:id,name', 'iterationLogs'])
            ->find($validated['benchmark_id']);

        if (! $benchmark) {
            return Response::error('Benchmark not found.');
        }

        $logs = $benchmark->iterationLogs;
        $keepCount = $logs->where('outcome', IterationOutcome::Keep)->count();
        $discardCount = $logs->where('outcome', IterationOutcome::Discard)->count();
        $crashCount = $logs->where('outcome', IterationOutcome::Crash)->count();

        $recentLogs = $logs->sortByDesc('iteration_number')->take(5)->values()->map(fn ($log) => [
            'iteration' => $log->iteration_number,
            'outcome' => $log->outcome->value,
            'metric_value' => $log->metric_value,
            'effective_improvement' => $log->effective_improvement,
            'duration_ms' => $log->duration_ms,
        ]);

        return Response::text(json_encode([
            'id' => $benchmark->id,
            'skill_id' => $benchmark->skill_id,
            'skill_name' => $benchmark->skill?->name,
            'status' => $benchmark->status->value,
            'metric_name' => $benchmark->metric_name,
            'metric_direction' => $benchmark->metric_direction,
            'baseline_value' => $benchmark->baseline_value,
            'best_value' => $benchmark->best_value,
            'improvement_percent' => $benchmark->improvementPercent(),
            'iteration_count' => $benchmark->iteration_count,
            'max_iterations' => $benchmark->max_iterations,
            'elapsed_seconds' => $benchmark->elapsedSeconds(),
            'time_budget_seconds' => $benchmark->time_budget_seconds,
            'keep_count' => $keepCount,
            'discard_count' => $discardCount,
            'crash_count' => $crashCount,
            'started_at' => $benchmark->started_at?->toIso8601String(),
            'completed_at' => $benchmark->completed_at?->toIso8601String(),
            'recent_iterations' => $recentLogs,
        ]));
    }
}
