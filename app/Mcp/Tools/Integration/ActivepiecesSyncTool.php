<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\SyncActivepiecesToolsAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool: trigger an on-demand Activepieces piece sync.
 *
 * Fetches the piece catalogue from the connected Activepieces instance and
 * upserts each piece as an MCP-HTTP Tool record in FleetQ.
 */
#[IsDestructive]
class ActivepiecesSyncTool extends Tool
{
    protected string $name = 'activepieces_sync';

    protected string $description = 'Sync Activepieces pieces as MCP-HTTP tools. Fetches the latest piece catalogue from the connected Activepieces integration and upserts each piece as an agent tool.';

    public function __construct(
        private readonly SyncActivepiecesToolsAction $syncAction,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('UUID of the Activepieces integration to sync. If omitted, the first active Activepieces integration for the team is used.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $integrationId = $request->get('integration_id');

        $query = Integration::withoutGlobalScopes()
            ->where('driver', 'activepieces')
            ->where('status', IntegrationStatus::Active);

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        if ($integrationId) {
            $query->where('id', $integrationId);
        }

        /** @var Integration|null $integration */
        $integration = $query->first();

        if (! $integration) {
            return Response::error('No active Activepieces integration found.');
        }

        try {
            $result = $this->syncAction->execute($integration);

            return Response::text(json_encode([
                'upserted' => $result->upserted,
                'deactivated' => $result->deactivated,
                'message' => $result->message,
            ]));
        } catch (\Throwable $e) {
            return Response::error('Sync failed: '.$e->getMessage());
        }
    }
}
