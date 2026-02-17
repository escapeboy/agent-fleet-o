<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ConnectorConfigGetTool extends Tool
{
    protected string $name = 'connector_config_get';

    protected string $description = 'Get a specific outbound connector config by ID or channel name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Config UUID'),
            'channel' => $schema->string()->description('Channel name (alternative to id): telegram, slack, discord, teams, google_chat, whatsapp, email, webhook'),
        ];
    }

    public function handle(Request $request): Response
    {
        $id = $request->get('id');
        $channel = $request->get('channel');

        if (! $id && ! $channel) {
            return Response::error('Provide either id or channel');
        }

        $config = $id
            ? OutboundConnectorConfig::find($id)
            : OutboundConnectorConfig::where('channel', $channel)->first();

        if (! $config) {
            return Response::error('Connector config not found');
        }

        $resolver = app(OutboundCredentialResolver::class);

        return Response::text(json_encode([
            'id' => $config->id,
            'channel' => $config->channel,
            'is_active' => $config->is_active,
            'source' => $resolver->getSource($config->channel),
            'masked_key' => $config->masked_key,
            'last_tested_at' => $config->last_tested_at?->toIso8601String(),
            'last_test_status' => $config->last_test_status,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ]));
    }
}
