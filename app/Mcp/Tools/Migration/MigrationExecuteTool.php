<?php

namespace App\Mcp\Tools\Migration;

use App\Domain\Migration\Actions\ExecuteMigrationAction;
use App\Domain\Migration\Models\MigrationRun;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class MigrationExecuteTool extends Tool
{
    protected string $name = 'migration_execute';

    protected string $description = 'Start execution of a migration run that is awaiting confirmation. Optionally override the column mapping. Returns immediately — the import runs asynchronously; poll `migration_status` for progress.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'run_id' => $schema->string()->description('Migration run ID returned by migration_detect_schema')->required(),
            'confirmed_mapping' => $schema->object()
                ->description('Optional final column → attribute map. When omitted, the detector proposal is used verbatim. Values may be empty strings or null to indicate "keep raw in metadata".'),
        ];
    }

    public function handle(Request $request, ExecuteMigrationAction $action): Response
    {
        $runId = (string) $request->get('run_id', '');
        if ($runId === '') {
            return Response::error('run_id is required');
        }

        $run = MigrationRun::find($runId);
        if ($run === null) {
            return Response::error("Migration run {$runId} not found or not in your team");
        }

        $mappingInput = $request->get('confirmed_mapping');
        $mapping = is_array($mappingInput) && $mappingInput !== [] ? $mappingInput : null;

        try {
            $run = $action->execute($run, $mapping);
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }

        return Response::text(json_encode([
            'run_id' => $run->id,
            'status' => $run->status->value,
            'confirmed_mapping' => $run->confirmed_mapping,
            'message' => 'Migration queued. Poll migration_status for progress.',
        ]));
    }
}
