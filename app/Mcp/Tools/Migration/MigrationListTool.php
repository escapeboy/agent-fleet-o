<?php

namespace App\Mcp\Tools\Migration;

use App\Domain\Migration\Models\MigrationRun;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MigrationListTool extends Tool
{
    protected string $name = 'migration_list';

    protected string $description = 'List recent migration runs for the team. Returns id, entity_type, status, stats and timestamps.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Max results (default 20, max 100)')->default(20),
            'status' => $schema->string()->description('Optional status filter (pending|analysing|awaiting_confirmation|running|completed|failed)')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $limit = min((int) ($request->get('limit', 20)), 100);
        $status = $request->get('status');

        $query = MigrationRun::query()->orderByDesc('created_at');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }
        $runs = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $runs->count(),
            'runs' => $runs->map(fn (MigrationRun $run) => [
                'id' => $run->id,
                'entity_type' => $run->entity_type->value,
                'source' => $run->source->value,
                'status' => $run->status->value,
                'stats' => $run->stats,
                'created_at' => $run->created_at?->diffForHumans(),
                'completed_at' => $run->completed_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
