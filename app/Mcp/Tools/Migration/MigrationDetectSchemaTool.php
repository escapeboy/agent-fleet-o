<?php

namespace App\Mcp\Tools\Migration;

use App\Domain\Migration\Actions\DetectSchemaAction;
use App\Domain\Migration\Enums\MigrationEntityType;
use App\Domain\Migration\Enums\MigrationSource;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class MigrationDetectSchemaTool extends Tool
{
    protected string $name = 'migration_detect_schema';

    protected string $description = 'Analyse a CSV or JSON export (e.g. from Salesforce, HubSpot, Intercom) and propose a column → FleetQ attribute mapping for a target entity type. Creates a migration run in `awaiting_confirmation` status. Call `migration_execute` after showing the proposal to the user.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'source_payload' => $schema->string()
                ->description('Raw CSV or JSON contents (up to 5 MB). First 10 rows are used for detection; the full payload is stored for the import step.')
                ->required(),
            'source' => $schema->string()
                ->description("Payload format: 'csv' or 'json'")
                ->enum(['csv', 'json'])
                ->default('csv'),
            'entity_type' => $schema->string()
                ->description("Target FleetQ entity. Currently only 'contact' is supported.")
                ->enum(['contact'])
                ->default('contact'),
        ];
    }

    public function handle(Request $request, DetectSchemaAction $action): Response
    {
        $user = auth()->user();
        if (! $user) {
            return Response::error('Authentication required');
        }

        $payload = (string) $request->get('source_payload', '');
        if ($payload === '') {
            return Response::error('source_payload is required');
        }

        try {
            $source = MigrationSource::from((string) $request->get('source', 'csv'));
            $entityType = MigrationEntityType::from((string) $request->get('entity_type', 'contact'));
        } catch (\ValueError $e) {
            return Response::error('Invalid source or entity_type: '.$e->getMessage());
        }

        try {
            $run = $action->execute($user, $payload, $source, $entityType);
        } catch (\Throwable $e) {
            return Response::error('Schema detection failed: '.$e->getMessage());
        }

        return Response::text(json_encode([
            'run_id' => $run->id,
            'status' => $run->status->value,
            'entity_type' => $run->entity_type->value,
            'proposed_mapping' => $run->proposed_mapping,
            'stats' => $run->stats,
        ]));
    }
}
