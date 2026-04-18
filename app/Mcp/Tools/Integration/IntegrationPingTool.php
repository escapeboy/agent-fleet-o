<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
#[AssistantTool('write')]
class IntegrationPingTool extends Tool
{
    protected string $name = 'integration_ping';

    protected string $description = 'Health-check a connected integration to verify credentials are still valid.';

    public function __construct(private readonly PingIntegrationAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_id' => $schema->string()
                ->description('Integration UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('No team context.');
        }

        $integration = Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('integration_id'))
            ->first();

        if (! $integration) {
            return Response::error('Integration not found.');
        }

        $result = $this->action->execute($integration);

        return Response::text(json_encode([
            'healthy' => $result->healthy,
            'message' => $result->message,
            'latency_ms' => $result->latencyMs,
            'checked_at' => $result->checkedAt?->toIso8601String(),
        ]));
    }
}
