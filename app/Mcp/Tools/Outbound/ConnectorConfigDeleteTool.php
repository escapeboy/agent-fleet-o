<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('destructive')]
class ConnectorConfigDeleteTool extends Tool
{
    protected string $name = 'connector_config_delete';

    protected string $description = 'Delete an outbound connector config. The channel will be unconfigured and inactive.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Config UUID to delete')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $config = OutboundConnectorConfig::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $config) {
            return Response::error('Connector config not found');
        }

        $channel = $config->channel;
        $config->delete();

        return Response::text(json_encode([
            'deleted' => true,
            'channel' => $channel,
            'message' => "Config removed. {$channel} channel is now unconfigured and inactive.",
        ]));
    }
}
