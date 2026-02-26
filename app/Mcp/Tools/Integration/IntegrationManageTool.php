<?php

namespace App\Mcp\Tools\Integration;

use App\Domain\Integration\Actions\ConnectIntegrationAction;
use App\Domain\Integration\Actions\DisconnectIntegrationAction;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Actions\PingIntegrationAction;
use App\Domain\Integration\Models\Integration;
use App\Domain\Integration\Services\IntegrationManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for managing external integrations.
 *
 * Actions:
 *   list             — List available drivers and connected integrations
 *   connect          — Connect a new integration (validate + save credentials)
 *   disconnect       — Disconnect an integration
 *   ping             — Health-check a specific integration
 *   execute          — Execute a driver action (e.g. send_message, create_issue)
 *   list_triggers    — List available triggers for a driver
 *   list_actions     — List available actions for a driver
 */
class IntegrationManageTool extends Tool
{
    protected string $name = 'integration_manage';

    protected string $description = 'Manage external service integrations (GitHub, Slack, Stripe, Notion, Airtable, Linear, webhooks). Actions: list, connect, disconnect, ping, execute, list_triggers, list_actions.';

    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly ConnectIntegrationAction $connectAction,
        private readonly DisconnectIntegrationAction $disconnectAction,
        private readonly PingIntegrationAction $pingAction,
        private readonly ExecuteIntegrationActionAction $executeAction,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list | connect | disconnect | ping | execute | list_triggers | list_actions')
                ->required(),
            'driver' => $schema->string()
                ->description('Driver slug: github | slack | stripe | notion | airtable | linear | api_polling | webhook'),
            'integration_id' => $schema->string()
                ->description('Integration UUID (required for disconnect, ping, execute)'),
            'name' => $schema->string()
                ->description('Integration name (required for connect)'),
            'credentials' => $schema->object()
                ->description('Credentials object for connect, e.g. {"token": "ghp_..."}'),
            'config' => $schema->object()
                ->description('Configuration object for connect (driver-specific, e.g. {"database_id": "..."} for Notion)'),
            'integration_action' => $schema->string()
                ->description('Driver action to execute (required for execute), e.g. send_message, create_issue'),
            'params' => $schema->object()
                ->description('Parameters for the driver action (required for execute)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return Response::error('No team context. Ensure MCP authentication is configured.');
        }

        return match ($action) {
            'list' => $this->list($teamId),
            'connect' => $this->connect($request, $teamId),
            'disconnect' => $this->disconnect($request, $teamId),
            'ping' => $this->ping($request, $teamId),
            'execute' => $this->execute($request, $teamId),
            'list_triggers' => $this->listTriggers($request),
            'list_actions' => $this->listActions($request),
            default => Response::error("Unknown action: {$action}. Valid: list, connect, disconnect, ping, execute, list_triggers, list_actions"),
        };
    }

    private function list(string $teamId): Response
    {
        $availableDrivers = collect(config('integrations.drivers', []))->map(fn ($config, $slug) => [
            'slug' => $slug,
            'label' => $config['label'],
            'auth' => $config['auth'],
            'poll_frequency' => $config['poll_frequency'],
        ])->values();

        $connectedIntegrations = Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->get()
            ->map(fn (Integration $i) => [
                'id' => $i->getKey(),
                'driver' => $i->getAttribute('driver'),
                'name' => $i->getAttribute('name'),
                'status' => $i->getAttribute('status'),
                'last_pinged_at' => $i->getAttribute('last_pinged_at')?->toIso8601String(),
                'error_count' => $i->getAttribute('error_count'),
            ]);

        return Response::text(json_encode([
            'available_drivers' => $availableDrivers,
            'connected_integrations' => $connectedIntegrations,
        ]));
    }

    private function connect(Request $request, string $teamId): Response
    {
        $driver = $request->get('driver');
        $name = $request->get('name');

        if (! $driver || ! $name) {
            return Response::error('driver and name are required for connect.');
        }

        $credentialsRaw = $request->get('credentials', []);
        $configRaw = $request->get('config', []);
        $credentials = is_array($credentialsRaw) ? $credentialsRaw : [];
        $config = is_array($configRaw) ? $configRaw : [];

        try {
            $integration = $this->connectAction->execute(
                teamId: $teamId,
                driver: $driver,
                name: $name,
                credentials: $credentials,
                config: $config,
            );

            return Response::text(json_encode([
                'success' => true,
                'integration_id' => $integration->getKey(),
                'driver' => $integration->getAttribute('driver'),
                'name' => $integration->getAttribute('name'),
                'status' => $integration->getAttribute('status'),
            ]));
        } catch (\Throwable $e) {
            return Response::error('Connection failed: '.$e->getMessage());
        }
    }

    private function disconnect(Request $request, string $teamId): Response
    {
        $integration = $this->findIntegration($request, $teamId);

        if (! $integration) {
            return Response::error('Integration not found.');
        }

        $this->disconnectAction->execute($integration);

        return Response::text(json_encode(['success' => true, 'message' => 'Integration disconnected.']));
    }

    private function ping(Request $request, string $teamId): Response
    {
        $integration = $this->findIntegration($request, $teamId);

        if (! $integration) {
            return Response::error('Integration not found.');
        }

        $result = $this->pingAction->execute($integration);

        return Response::text(json_encode([
            'healthy' => $result->healthy,
            'message' => $result->message,
            'latency_ms' => $result->latencyMs,
            'checked_at' => $result->checkedAt?->toIso8601String(),
        ]));
    }

    private function execute(Request $request, string $teamId): Response
    {
        $integration = $this->findIntegration($request, $teamId);

        if (! $integration) {
            return Response::error('Integration not found.');
        }

        $integrationAction = $request->get('integration_action');
        $paramsRaw = $request->get('params', []);
        $params = is_array($paramsRaw) ? $paramsRaw : [];

        if (! $integrationAction) {
            return Response::error('integration_action is required for execute.');
        }

        try {
            $result = $this->executeAction->execute(
                integration: $integration,
                action: $integrationAction,
                params: $params,
            );

            return Response::text(json_encode(['success' => true, 'result' => $result]));
        } catch (\Throwable $e) {
            return Response::error('Execute failed: '.$e->getMessage());
        }
    }

    private function listTriggers(Request $request): Response
    {
        $driver = $request->get('driver');

        if (! $driver) {
            return Response::error('driver is required for list_triggers.');
        }

        $driverInstance = $this->manager->driver($driver);

        $triggers = array_map(fn ($t) => [
            'key' => $t->key,
            'label' => $t->label,
            'description' => $t->description,
        ], $driverInstance->triggers());

        return Response::text(json_encode(['driver' => $driver, 'triggers' => $triggers]));
    }

    private function listActions(Request $request): Response
    {
        $driver = $request->get('driver');

        if (! $driver) {
            return Response::error('driver is required for list_actions.');
        }

        $driverInstance = $this->manager->driver($driver);

        $actions = array_map(fn ($a) => [
            'key' => $a->key,
            'label' => $a->label,
            'description' => $a->description,
            'input_schema' => $a->inputSchema,
        ], $driverInstance->actions());

        return Response::text(json_encode(['driver' => $driver, 'actions' => $actions]));
    }

    private function findIntegration(Request $request, string $teamId): ?Integration
    {
        $integrationId = $request->get('integration_id');

        if (! $integrationId) {
            return null;
        }

        return Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $integrationId)
            ->first();
    }
}
