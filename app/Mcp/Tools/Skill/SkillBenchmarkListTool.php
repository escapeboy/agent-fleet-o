<?php

namespace App\Mcp\Tools\Skill;

use App\Domain\Skill\Models\SkillBenchmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SkillBenchmarkListTool extends Tool
{
    protected string $name = 'skill_benchmark_list';

    protected string $description = 'List skill benchmarks for the current team, optionally filtered by skill or status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill_id' => $schema->string()
                ->description('Filter by skill UUID (optional)'),
            'status' => $schema->string()
                ->description('Filter by status: pending, running, completed, cancelled, failed (optional)')
                ->enum(['pending', 'running', 'completed', 'cancelled', 'failed']),
            'limit' => $schema->integer()
                ->description('Maximum results to return (default: 20, max: 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'skill_id' => 'sometimes|string',
            'status' => 'sometimes|in:pending,running,completed,cancelled,failed',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $query = SkillBenchmark::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->with('skill:id,name')
            ->orderByDesc('created_at');

        if (isset($validated['skill_id'])) {
            $query->where('skill_id', $validated['skill_id']);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $limit = min((int) ($validated['limit'] ?? 20), 100);
        $benchmarks = $query->limit($limit)->get();

        return Response::text(json_encode(
            $benchmarks->map(fn ($b) => [
                'id' => $b->id,
                'skill_id' => $b->skill_id,
                'skill_name' => $b->skill?->name,
                'status' => $b->status->value,
                'metric_name' => $b->metric_name,
                'baseline_value' => $b->baseline_value,
                'best_value' => $b->best_value,
                'improvement_percent' => $b->improvementPercent(),
                'iteration_count' => $b->iteration_count,
                'max_iterations' => $b->max_iterations,
                'started_at' => $b->started_at?->toIso8601String(),
                'completed_at' => $b->completed_at?->toIso8601String(),
            ])->values(),
        ));
    }
}
