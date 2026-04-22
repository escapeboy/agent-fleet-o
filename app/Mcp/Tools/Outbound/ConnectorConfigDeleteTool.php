<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class ConnectorConfigDeleteTool extends Tool
{
    use HasStructuredErrors;

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
            return $this->permissionDeniedError('No current team.');
        }
        $config = OutboundConnectorConfig::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $config) {
            return $this->notFoundError('connector config');
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
