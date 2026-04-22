<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Shared\Services\SsrfGuard;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CustomEndpointManageTool extends Tool
{
    use HasStructuredErrors;

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
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $action = $request->get('action');

        return match ($action) {
            'list' => $this->listEndpoints($teamId),
            'add' => $this->addEndpoint($teamId, $request),
            'update' => $this->updateEndpoint($teamId, $request),
            'toggle' => $this->toggleEndpoint($teamId, $request),
            'remove' => $this->removeEndpoint($teamId, $request),
            default => $this->invalidArgumentError("Unknown action: {$action}"),
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
            return $this->invalidArgumentError('name, base_url, and models are required for add action.');
        }

        if (! preg_match('/^[a-z0-9_-]+$/', $name)) {
            return $this->invalidArgumentError('Name must be lowercase letters, numbers, hyphens, and underscores only.');
        }

        try {
            app(SsrfGuard::class)->assertPublicUrl($baseUrl);
        } catch (\InvalidArgumentException $e) {
            throw $e;
        }

        $modelList = array_filter(array_map('trim', explode(',', $models)));

        $existing = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->exists();

        if ($existing) {
            return $this->failedPreconditionError("Endpoint '{$name}' already exists. Use 'update' action to modify it.");
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
            return $this->invalidArgumentError('name is required for update action.');
        }

        $endpoint = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->first();

        if (! $endpoint) {
            return $this->notFoundError('endpoint', $name);
        }

        $creds = $endpoint->credentials;

        if ($request->get('base_url')) {
            try {
                app(SsrfGuard::class)->assertPublicUrl($request->get('base_url'));
            } catch (\InvalidArgumentException $e) {
                throw $e;
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
            return $this->invalidArgumentError('name is required for toggle action.');
        }

        $endpoint = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->first();

        if (! $endpoint) {
            return $this->notFoundError('endpoint', $name);
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
            return $this->invalidArgumentError('name is required for remove action.');
        }

        $deleted = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', 'custom_endpoint')
            ->where('name', $name)
            ->delete();

        if (! $deleted) {
            return $this->notFoundError('endpoint', $name);
        }

        return Response::text(json_encode([
            'success' => true,
            'name' => $name,
            'message' => "Endpoint '{$name}' removed.",
        ]));
    }
}
