<?php

namespace App\Mcp\Tools\Outbound;

use App\Domain\Outbound\Models\OutboundConnectorConfig;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ConnectorConfigGetTool extends Tool
{
    use HasStructuredErrors;

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
            return $this->invalidArgumentError('Provide either id or channel');
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $config = $id
            ? OutboundConnectorConfig::withoutGlobalScopes()->where('team_id', $teamId)->find($id)
            : OutboundConnectorConfig::withoutGlobalScopes()->where('team_id', $teamId)->where('channel', $channel)->first();

        if (! $config) {
            return $this->notFoundError('connector config');
        }

        $resolver = app(OutboundCredentialResolver::class);

        /** @var Carbon|null $lastTestedAt */
        $lastTestedAt = $config->last_tested_at;

        return Response::text(json_encode([
            'id' => $config->id,
            'channel' => $config->channel,
            'is_active' => $config->is_active,
            'source' => $resolver->getSource($config->channel),
            'masked_key' => $config->masked_key,
            'last_tested_at' => $lastTestedAt?->toIso8601String(),
            'last_test_status' => $config->last_test_status,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ]));
    }
}
