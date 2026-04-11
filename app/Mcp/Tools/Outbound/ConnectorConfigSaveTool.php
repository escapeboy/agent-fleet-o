<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ConnectorConfigSaveTool extends Tool
{
    protected string $name = 'connector_config_save';

    protected string $description = 'Create or update an outbound connector config. Upserts by channel. Core channels: email, webhook, notification. Legacy channels (telegram, slack, etc.) are supported for backward compatibility but should be handled by agents via tools instead.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Channel: email, webhook, notification (core). Also accepts legacy channels: telegram, slack, discord, teams, google_chat, whatsapp.')
                ->required(),
            'credentials' => $schema->object()
                ->description('Channel-specific credentials object. E.g. {host: "...", port: 587, username: "...", password: "...", encryption: "tls", from_address: "...", from_name: "..."} for email, {default_url: "...", secret: "..."} for webhook.')
                ->required(),
            'is_active' => $schema->boolean()
                ->description('Whether the config is active')
                ->default(true),
        ];
    }

    public function handle(Request $request): Response
    {
        $channel = $request->get('channel');
        $validChannels = ['email', 'webhook', 'notification', 'telegram', 'slack', 'discord', 'teams', 'google_chat', 'whatsapp', 'ntfy'];

        if (! in_array($channel, $validChannels)) {
            return Response::error('Invalid channel. Must be one of: '.implode(', ', $validChannels));
        }

        $credentials = $request->get('credentials');
        if (! is_array($credentials) || empty(array_filter($credentials, fn ($v) => $v !== null && $v !== ''))) {
            return Response::error('Credentials must be a non-empty object with at least one value');
        }

        $teamId = app('mcp.team_id') ?? null;

        $config = OutboundConnectorConfig::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $teamId, 'channel' => $channel],
            [
                'credentials' => $credentials,
                'is_active' => $request->get('is_active', true),
            ],
        );

        return Response::text(json_encode([
            'id' => $config->id,
            'channel' => $config->channel,
            'is_active' => $config->is_active,
            'masked_key' => $config->masked_key,
            'created' => $config->wasRecentlyCreated,
        ]));
    }
}
