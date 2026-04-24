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
class MigrationStatusTool extends Tool
{
    protected string $name = 'migration_status';

    protected string $description = 'Get status, stats, and up to 100 per-row errors for a migration run.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->description('Migration run ID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $runId = (string) $request->get('run_id', '');
        if ($runId === '') {
            return Response::error('run_id is required');
        }

        $run = MigrationRun::find($runId);
        if ($run === null) {
            return Response::error("Migration run {$runId} not found");
        }

        return Response::text(json_encode([
            'run_id' => $run->id,
            'status' => $run->status->value,
            'entity_type' => $run->entity_type->value,
            'source' => $run->source->value,
            'proposed_mapping' => $run->proposed_mapping,
            'confirmed_mapping' => $run->confirmed_mapping,
            'stats' => $run->stats,
            'errors' => $run->errors,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ]));
    }
}
