<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Services\LocalLlmUrlValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class LocalLlmTool extends Tool
{
    protected string $name = 'local_llm_manage';

    protected string $description = 'Manage local LLM HTTP endpoints (Ollama, OpenAI-compatible). '
        .'Actions: status, configure_ollama, configure_openai_compatible, discover_models, remove.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: status | configure_ollama | configure_openai_compatible | discover_models | remove')
                ->required(),
            'provider' => $schema->string()
                ->description('Provider: ollama | openai_compatible (required for configure/remove/discover)'),
            'base_url' => $schema->string()
                ->description('Base URL of the endpoint (e.g. http://localhost:11434)'),
            'api_key' => $schema->string()
                ->description('Optional API key for authenticated endpoints'),
            'models' => $schema->string()
                ->description('Comma-separated model IDs for openai_compatible endpoints'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');

        return match ($action) {
            'status' => $this->handleStatus($request),
            'configure_ollama' => $this->handleConfigureOllama($request),
            'configure_openai_compatible' => $this->handleConfigureOpenaiCompatible($request),
            'discover_models' => $this->handleDiscoverModels($request),
            'remove' => $this->handleRemove($request),
            default => Response::error("Unknown action '{$action}'. Valid: status, configure_ollama, configure_openai_compatible, discover_models, remove"),
        };
    }

    private function handleStatus(Request $request): Response
    {
        if (! config('local_llm.enabled', false)) {
            return Response::text(json_encode([
                'enabled' => false,
                'message' => 'Local LLM support is disabled. Set LOCAL_LLM_ENABLED=true to enable.',
            ]));
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $credentials = TeamProviderCredential::where('team_id', $teamId)
            ->whereIn('provider', ['ollama', 'openai_compatible'])
            ->get()
            ->keyBy('provider');

        $status = ['enabled' => true, 'providers' => []];

        foreach (['ollama', 'openai_compatible'] as $provider) {
            if ($credentials->has($provider)) {
                $creds = $credentials->get($provider)->credentials;
                $status['providers'][$provider] = [
                    'configured' => true,
                    'base_url' => $creds['base_url'] ?? null,
                    'has_api_key' => ! empty($creds['api_key']),
                    'models' => $creds['models'] ?? [],
                ];
            } else {
                $status['providers'][$provider] = ['configured' => false];
            }
        }

        return Response::text(json_encode($status));
    }

    private function handleConfigureOllama(Request $request): Response
    {
        $baseUrl = $request->get('base_url');
        if (! $baseUrl) {
            return Response::error('base_url is required for configure_ollama');
        }

        try {
            app(LocalLlmUrlValidator::class)->validate($baseUrl);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $teamId, 'provider' => 'ollama'],
            ['credentials' => [
                'base_url' => rtrim($baseUrl, '/'),
                'api_key' => $request->get('api_key', ''),
            ], 'is_active' => true],
        );

        return Response::text("Ollama endpoint configured: {$baseUrl}");
    }

    private function handleConfigureOpenaiCompatible(Request $request): Response
    {
        $baseUrl = $request->get('base_url');
        if (! $baseUrl) {
            return Response::error('base_url is required for configure_openai_compatible');
        }

        try {
            app(LocalLlmUrlValidator::class)->validate($baseUrl);
        } catch (\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }

        $models = array_filter(array_map('trim', explode(',', $request->get('models', ''))));
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        TeamProviderCredential::updateOrCreate(
            ['team_id' => $teamId, 'provider' => 'openai_compatible'],
            ['credentials' => [
                'base_url' => rtrim($baseUrl, '/'),
                'api_key' => $request->get('api_key', ''),
                'models' => array_values($models),
            ], 'is_active' => true],
        );

        return Response::text("OpenAI-compatible endpoint configured: {$baseUrl}");
    }

    private function handleDiscoverModels(Request $request): Response
    {
        $provider = $request->get('provider');
        if (! in_array($provider, ['ollama', 'openai_compatible'], true)) {
            return Response::error("provider must be 'ollama' or 'openai_compatible'");
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $credential = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (! $credential) {
            return Response::error("No {$provider} endpoint configured. Run configure_{$provider} first.");
        }

        $baseUrl = $credential->credentials['base_url'] ?? null;
        if (! $baseUrl) {
            return Response::error("No base_url in {$provider} credentials.");
        }

        $apiKey = $credential->credentials['api_key'] ?? '';

        try {
            $endpoint = $provider === 'ollama'
                ? rtrim($baseUrl, '/').'/api/tags'
                : rtrim($baseUrl, '/').'/models';

            $httpRequest = Http::timeout(10);
            if ($apiKey) {
                $httpRequest = $httpRequest->withToken($apiKey);
            }

            $response = $httpRequest->get($endpoint);

            if (! $response->ok()) {
                return Response::error("Could not reach {$provider} at {$baseUrl}: HTTP {$response->status()}");
            }

            $data = $response->json();

            $models = $provider === 'ollama'
                ? array_column($data['models'] ?? [], 'name')
                : array_column($data['data'] ?? [], 'id');

            return Response::text(json_encode([
                'provider' => $provider,
                'base_url' => $baseUrl,
                'models' => $models,
                'count' => count($models),
            ]));
        } catch (ConnectionException $e) {
            return Response::error("Connection failed to {$baseUrl}: {$e->getMessage()}");
        }
    }

    private function handleRemove(Request $request): Response
    {
        $provider = $request->get('provider');
        if (! in_array($provider, ['ollama', 'openai_compatible'], true)) {
            return Response::error("provider must be 'ollama' or 'openai_compatible'");
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $deleted = TeamProviderCredential::where('team_id', $teamId)
            ->where('provider', $provider)
            ->delete();

        return Response::text($deleted
            ? "Removed {$provider} endpoint."
            : "No {$provider} endpoint was configured.",
        );
    }
}
