<?php

namespace App\Mcp\Tools\System;

use App\Infrastructure\AI\Models\ShadowComparison;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ShadowTrafficSummaryTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'shadow_traffic_summary';

    protected string $description = 'Summarise sampled shadow-traffic A/B comparisons for the current team: per shadow model, the output-match rate and the average cost/latency delta vs the primary model. Use to decide whether a candidate model is cheaper/faster/equivalent before switching to it.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max recent comparisons to include in the sample (default 50, max 200)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $limit = $validated['limit'] ?? 50;

        $rows = ShadowComparison::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $completed = $rows->where('shadow_status', 'completed');

        $byModel = $completed
            ->groupBy(fn (ShadowComparison $c) => $c->shadow_provider.'/'.$c->shadow_model)
            ->map(function ($group) {
                $n = $group->count();
                $matches = $group->where('outputs_match', true)->count();

                return [
                    'samples' => $n,
                    'output_match_rate' => $n > 0 ? round($matches / $n, 3) : null,
                    'avg_primary_cost_credits' => round((float) $group->avg('primary_cost_credits'), 2),
                    'avg_shadow_cost_credits' => round((float) $group->avg('shadow_cost_credits'), 2),
                    'avg_primary_latency_ms' => (int) round((float) $group->avg('primary_latency_ms')),
                    'avg_shadow_latency_ms' => (int) round((float) $group->avg('shadow_latency_ms')),
                ];
            });

        return Response::text(json_encode([
            'total_sampled' => $rows->count(),
            'completed' => $completed->count(),
            'failed' => $rows->where('shadow_status', 'failed')->count(),
            'by_shadow_model' => $byModel,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
