<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CustomEndpointManageTool extends Tool
{
    protected string $name = 'custom_endpoint_manage';

    protected string $description = 'Manage custom AI endpoints (OpenAI-compatible proxies, gateways, hosted models). List, add, update, toggle, or remove endpoints. Endpoints use zero-cost tracking — billing is handled by the external service.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: list, add, update, toggle, remove')
                ->enum(['list', 'add', 'update', 'toggle', 'remove'])
                ->required(),
            'name' => $schema->string()
                ->description('Endpoint name (lowercase, hyphens, underscores). Required for add/update/toggle/remove.'),
            'base_url' => $schema->string()
                ->description('Base URL of the OpenAI-compatible API (e.g. https://proxy.example.com/v1). Required for add.'),
            'api_key' => $schema->string()
                ->description('API key for the endpoint (optional, encrypted at rest).'),
            'models' => $schema->string()
                ->description('Comma-separated list of model IDs available at this endpoint. Required for add.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();
        $teamId = $user?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $action = $request->get('action');

        return match ($action) {
            'list' => $this->listEndpoints($teamId),
            'add' => $this->addEndpoint($teamId, $request),
            'update' => $this->updateEndpoint($teamId, $request),
            'toggle' => $this->toggleEndpoint($teamId, $request),
            'remove' => $this->removeEndpoint($teamId, $request),
            default => Response::error("Unknown action: {$action}"),
        };
    }

    private function listEndpoints(string $teamId): Response
    {
        $endpoints = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->get();

        return Response::text(json_encode([
            'count' => $endpoints->count(),
            'endpoints' => $endpoints->map(fn ($ep) => [
                'id' => $ep->id,
                'name' => $ep->name,
                'base_url' => $ep->credentials['base_url'] ?? null,
                'models' => $ep->credentials['models'] ?? [],
                'has_api_key' => ! empty($ep->credentials['api_key']),
                'is_active' => $ep->is_active,
                'updated_at' => $ep->updated_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }

    private function addEndpoint(string $teamId, Request $request): Response
    {
        $name = $request->get('name');
        $baseUrl = $request->get('base_url');
        $models = $request->get('models');

        if (! $name || ! $baseUrl || ! $models) {
            return Response::error('name, base_url, and models are required for add action.');
        }

        if (! preg_match('/^[a-z0-9_-]+$/', $name)) {
            return Response::error('Name must be lowercase letters, numbers, hyphens, and underscores only.');
        }

        try {
            app(SsrfGuard::class)->assertPublicUrl($baseUrl);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $modelList = array_filter(array_map('trim', explode(',', $models)));

        $existing = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->exists();

        if ($existing) {
            return Response::error("Endpoint '{$name}' already exists. Use 'update' action to modify it.");
        }

        TeamProviderCredential::create([
            'team_id' => $teamId,
            'provider' => 'custom_endpoint',
            'name' => $name,
            'credentials' => [
                'base_url' => rtrim($baseUrl, '/'),
                'api_key' => $request->get('api_key', ''),
                'models' => $modelList,
            ],
            'is_active' => true,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'name' => $name,
            'models' => $modelList,
            'message' => "Custom endpoint '{$name}' added. Use provider='custom_endpoint:{$name}' in agents and skills.",
        ]));
    }

    private function updateEndpoint(string $teamId, Request $request): Response
    {
        $name = $request->get('name');

        if (! $name) {
            return Response::error('name is required for update action.');
        }

        $endpoint = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->first();

        if (! $endpoint) {
            return Response::error("Endpoint '{$name}' not found.");
        }

        $creds = $endpoint->credentials;

        if ($request->get('base_url')) {
            try {
                app(SsrfGuard::class)->assertPublicUrl($request->get('base_url'));
            } catch (\InvalidArgumentException $e) {
                return Response::error($e->getMessage());
            }
            $creds['base_url'] = rtrim($request->get('base_url'), '/');
        }

        if ($request->get('api_key') !== null) {
            $creds['api_key'] = $request->get('api_key');
        }

        if ($request->get('models')) {
            $creds['models'] = array_filter(array_map('trim', explode(',', $request->get('models'))));
        }

        $endpoint->update(['credentials' => $creds]);

        return Response::text(json_encode([
            'success' => true,
            'name' => $name,
            'message' => "Endpoint '{$name}' updated.",
        ]));
    }

    private function toggleEndpoint(string $teamId, Request $request): Response
    {
        $name = $request->get('name');

        if (! $name) {
            return Response::error('name is required for toggle action.');
        }

        $endpoint = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->first();

        if (! $endpoint) {
            return Response::error("Endpoint '{$name}' not found.");
        }

        $endpoint->update(['is_active' => ! $endpoint->is_active]);

        $status = $endpoint->is_active ? 'activated' : 'deactivated';

        return Response::text(json_encode([
            'success' => true,
            'name' => $name,
            'is_active' => $endpoint->is_active,
            'message' => "Endpoint '{$name}' {$status}.",
        ]));
    }

    private function removeEndpoint(string $teamId, Request $request): Response
    {
        $name = $request->get('name');

        if (! $name) {
            return Response::error('name is required for remove action.');
        }

        $deleted = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->delete();

        if (! $deleted) {
            return Response::error("Endpoint '{$name}' not found.");
        }

        return Response::text(json_encode([
            'success' => true,
            'name' => $name,
            'message' => "Endpoint '{$name}' removed.",
        ]));
    }
}
