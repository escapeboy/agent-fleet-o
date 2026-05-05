<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Models\Integration;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool: list synced Activepieces pieces for a team.
 *
 * Returns all active Tool records that were synced from Activepieces,
 * grouped by integration. Useful for agents that need to discover
 * which Activepieces capabilities are available.
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ActivepiecesListPiecesTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'activepieces_list_pieces';

    protected string $description = 'List all Activepieces pieces that have been synced as MCP-HTTP tools. Returns piece name, display name, MCP endpoint URL, and last-synced timestamp.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('Filter by a specific Activepieces integration UUID. If omitted, all integrations for the team are included.'),
            'include_disabled' => $schema->boolean()
                ->description('Include disabled (deactivated) pieces. Default: false.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;
        $integrationId = $request->get('integration_id');
        $includeDisabled = (bool) $request->get('include_disabled', false);

        if ($integrationId) {
            // Verify the integration belongs to this team.
            $integration = Integration::withoutGlobalScopes()
                ->where('id', $integrationId)
                ->where('driver', 'activepieces')
                ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
                ->first();

            if (! $integration) {
                return $this->notFoundError('Activepieces integration');
            }
        }

        $query = ToolModel::withoutGlobalScopes()
            ->whereNotNull('settings')
            ->whereRaw("settings ? 'activepieces_piece_name'");

        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        if ($integrationId) {
            $query->whereRaw("settings->>'activepieces_integration_id' = ?", [$integrationId]);
        }

        if (! $includeDisabled) {
            $query->where('status', ToolStatus::Active);
        }

        $tools = $query->orderBy('name')->get();

        if ($tools->isEmpty()) {
            return Response::text(json_encode([
                'pieces' => [],
                'total' => 0,
                'note' => 'No Activepieces pieces synced yet. Run activepieces_sync to populate.',
            ]));
        }

        $pieces = $tools->map(function (ToolModel $tool): array {
            $settings = $tool->settings ?? [];

            return [
                'tool_id' => $tool->getKey(),
                'name' => $tool->name,
                'piece_name' => $settings['activepieces_piece_name'] ?? null,
                'mcp_url' => $tool->transport_config['url'] ?? null,
                'status' => $tool->status->value,
                'last_synced' => $settings['last_synced_at'] ?? null,
                'integration_id' => $settings['activepieces_integration_id'] ?? null,
            ];
        })->values()->all();

        return Response::text(json_encode([
            'pieces' => $pieces,
            'total' => count($pieces),
        ]));
    }
}
