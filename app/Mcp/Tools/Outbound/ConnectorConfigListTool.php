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
class ConnectorConfigListTool extends Tool
{
    protected string $name = 'connector_config_list';

    protected string $description = 'List outbound connector configurations. Returns channel, is_active, source (ui/env/none), masked_key, last_test_status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel' => $schema->string()
                ->description('Filter by channel: telegram, slack, discord, teams, google_chat, whatsapp, email, webhook'),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = OutboundConnectorConfig::query()->orderBy('channel');

        if ($channel = $request->get('channel')) {
            $query->where('channel', $channel);
        }

        $configs = $query->get();
        $resolver = app(OutboundCredentialResolver::class);

        return Response::text(json_encode([
            'count' => $configs->count(),
            'configs' => $configs->map(fn (OutboundConnectorConfig $c) => [
                'id' => $c->id,
                'channel' => $c->channel,
                'is_active' => $c->is_active,
                'source' => $resolver->getSource($c->channel),
                'masked_key' => $c->masked_key,
                'last_tested_at' => $c->last_tested_at?->toIso8601String(),
                'last_test_status' => $c->last_test_status,
            ])->toArray(),
        ]));
    }
}
