<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class CrewExecutionsListTool extends Tool
{
    protected string $name = 'crew_executions_list';

    protected string $description = 'List executions for a crew ordered by most recent. Returns id, status, goal, duration_ms, cost_credits, and started_at.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID')
                ->required(),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 50)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['crew_id' => 'required|string']);

        $crew = Crew::find($validated['crew_id']);

        if (! $crew) {
            return Response::error('Crew not found.');
        }

        $limit = min((int) ($request->get('limit', 10)), 50);

        $executions = CrewExecution::query()
            ->where('crew_id', $crew->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return Response::text(json_encode([
            'crew_id' => $crew->id,
            'crew_name' => $crew->name,
            'count' => $executions->count(),
            'executions' => $executions->map(fn ($e) => [
                'id' => $e->id,
                'status' => $e->status->value,
                'goal' => $e->goal,
                'duration_ms' => $e->duration_ms,
                'total_cost_credits' => $e->total_cost_credits,
                'started_at' => $e->started_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
