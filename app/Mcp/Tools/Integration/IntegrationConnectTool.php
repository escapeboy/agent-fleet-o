<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\ConnectIntegrationAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class IntegrationConnectTool extends Tool
{
    protected string $name = 'integration_connect';

    protected string $description = 'Connect a new external integration by providing driver credentials (API key, token, etc.).';

    public function __construct(private readonly ConnectIntegrationAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'driver' => $schema->string()
                ->description('Driver slug: github | slack | notion | linear | airtable | stripe | webhook')
                ->required(),
            'name' => $schema->string()
                ->description('Human-readable name for this integration instance')
                ->required(),
            'credentials' => $schema->object()
                ->description('Credential key-value pairs, e.g. {"token": "ghp_..."}'),
            'config' => $schema->object()
                ->description('Driver-specific config, e.g. {"database_id": "..."} for Notion'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('No team context.');
        }

        $driver = $request->get('driver');
        $name = $request->get('name');

        if (! $driver || ! $name) {
            return Response::error('driver and name are required.');
        }

        try {
            $integration = $this->action->execute(
                teamId: $teamId,
                driver: $driver,
                name: $name,
                credentials: (array) ($request->get('credentials') ?? []),
                config: (array) ($request->get('config') ?? []),
            );

            return Response::text(json_encode([
                'success' => true,
                'integration_id' => $integration->id,
                'driver' => $integration->driver,
                'name' => $integration->name,
                'status' => $integration->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error('Connection failed: '.$e->getMessage());
        }
    }
}
