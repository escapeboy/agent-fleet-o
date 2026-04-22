<?php

namespace App\Mcp\Tools\Compute;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\Compute\ComputeProviderManager;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\Services\ComputeCredentialResolver;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool for managing pluggable GPU compute providers.
 *
 * Actions:
 *   provider_list       — List all available compute providers and their credential status
 *   credential_save     — Save (or update) credentials for a compute provider
 *   credential_check    — Verify stored credentials are valid for a provider
 *   credential_remove   — Remove credentials for a compute provider
 *   health_check        — Check endpoint health for a provider
 *   run                 — Run a compute job on any configured provider
 */
#[IsDestructive]
#[AssistantTool('write')]
class ComputeManageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'compute_manage';

    protected string $description = 'Manage pluggable GPU compute providers (RunPod, Replicate, Fal.ai, Vast.ai). Actions: provider_list, credential_save, credential_check, credential_remove, health_check, run.';

    public function __construct(
        private readonly ComputeProviderManager $manager,
        private readonly ComputeCredentialResolver $credentialResolver,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: provider_list | credential_save | credential_check | credential_remove | health_check | run')
                ->required(),
            'provider' => $schema->string()
                ->description('Provider slug: runpod | replicate | fal | vast'),
            'api_key' => $schema->string()
                ->description('API key for credential_save'),
            'endpoint_id' => $schema->string()
                ->description('Provider endpoint/model identifier (required for health_check and run)'),
            'input' => $schema->object()
                ->description('Input payload for run'),
            'use_sync' => $schema->boolean()
                ->description('Use synchronous mode for run (default: true)'),
            'timeout_seconds' => $schema->integer()
                ->description('Max wait time in seconds for run (default: 90)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');
        $teamId = app('mcp.team_id') ?? null;

        return match ($action) {
            'provider_list' => $this->listProviders($teamId),
            'credential_save' => $this->saveCredential($request, $teamId),
            'credential_check' => $this->checkCredential($request, $teamId),
            'credential_remove' => $this->removeCredential($request, $teamId),
            'health_check' => $this->healthCheck($request, $teamId),
            'run' => $this->runJob($request, $teamId),
            default => $this->invalidArgumentError("Unknown action: {$action}. Valid: provider_list, credential_save, credential_check, credential_remove, health_check, run"),
        };
    }

    private function listProviders(?string $teamId): Response
    {
        $registered = config('compute_providers.providers', []);
        $result = [];

        foreach ($registered as $slug => $info) {
            $hasCredential = $teamId
                ? $this->credentialResolver->resolve($teamId, $slug) !== null
                : false;

            $result[] = [
                'provider' => $slug,
                'label' => $info['label'] ?? $slug,
                'credential_configured' => $hasCredential,
            ];
        }

        return Response::text(json_encode(['providers' => $result]));
    }

    private function saveCredential(Request $request, ?string $teamId): Response
    {
        $provider = $request->get('provider');
        $apiKey = $request->get('api_key');

        if (! $provider) {
            return $this->invalidArgumentError('provider is required for credential_save');
        }

        if (! $apiKey) {
            return $this->invalidArgumentError('api_key is required for credential_save');
        }

        if (! array_key_exists($provider, config('compute_providers.providers', []))) {
            return $this->invalidArgumentError("Unknown provider '{$provider}'. Available: ".implode(', ', array_keys(config('compute_providers.providers', []))));
        }

        // Validate credentials via the provider driver
        try {
            $providerInstance = $this->manager->driver($provider);
            $valid = $providerInstance->validateCredentials(['api_key' => $apiKey]);
        } catch (\Throwable $e) {
            throw $e;
        }

        if (! $valid) {
            return $this->failedPreconditionError("Credential validation failed for provider '{$provider}'. Check your API key.");
        }

        TeamProviderCredential::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $teamId, 'provider' => $provider],
            ['credentials' => ['api_key' => $apiKey], 'is_active' => true],
        );

        return Response::text(json_encode([
            'status' => 'saved',
            'provider' => $provider,
            'message' => "Credentials for '{$provider}' validated and saved successfully.",
        ]));
    }

    private function checkCredential(Request $request, ?string $teamId): Response
    {
        $provider = $request->get('provider');

        if (! $provider) {
            return $this->invalidArgumentError('provider is required for credential_check');
        }

        $credentials = $teamId ? $this->credentialResolver->resolve($teamId, $provider) : null;

        if (! $credentials) {
            return Response::text(json_encode([
                'provider' => $provider,
                'configured' => false,
                'valid' => false,
                'message' => "No credentials configured for '{$provider}'.",
            ]));
        }

        try {
            $providerInstance = $this->manager->driver($provider);
            $valid = $providerInstance->validateCredentials($credentials);
        } catch (\Throwable $e) {
            throw $e;
        }

        return Response::text(json_encode([
            'provider' => $provider,
            'configured' => true,
            'valid' => $valid,
            'message' => $valid
                ? 'Credentials are valid.'
                : 'Credentials appear invalid or the provider API is unreachable.',
        ]));
    }

    private function removeCredential(Request $request, ?string $teamId): Response
    {
        $provider = $request->get('provider');

        if (! $provider) {
            return $this->invalidArgumentError('provider is required for credential_remove');
        }

        $deleted = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', $provider)
            ->delete();

        return Response::text(json_encode([
            'provider' => $provider,
            'status' => $deleted ? 'removed' : 'not_found',
            'message' => $deleted
                ? "Credentials for '{$provider}' removed."
                : "No credentials found for '{$provider}'.",
        ]));
    }

    private function healthCheck(Request $request, ?string $teamId): Response
    {
        $provider = $request->get('provider');
        $endpointId = $request->get('endpoint_id');

        if (! $provider || ! $endpointId) {
            return $this->invalidArgumentError('provider and endpoint_id are required for health_check');
        }

        $credentials = $teamId ? ($this->credentialResolver->resolve($teamId, $provider) ?? []) : [];

        try {
            $providerInstance = $this->manager->driver($provider);

            $job = new ComputeJobDTO(
                provider: $provider,
                endpointId: $endpointId,
                input: [],
                credentials: $credentials,
            );

            $health = $providerInstance->health($job);
        } catch (\Throwable $e) {
            throw $e;
        }

        return Response::text(json_encode([
            'provider' => $provider,
            'endpoint_id' => $endpointId,
            'healthy' => $health->healthy,
            'workers_ready' => $health->workersReady,
            'workers_running' => $health->workersRunning,
            'jobs_in_queue' => $health->jobsInQueue,
            'message' => $health->message,
        ]));
    }

    private function runJob(Request $request, ?string $teamId): Response
    {
        $provider = $request->get('provider', config('compute_providers.default', 'runpod'));
        $endpointId = $request->get('endpoint_id');

        if (! $endpointId) {
            return $this->invalidArgumentError('endpoint_id is required for run');
        }

        if (! $teamId) {
            return $this->permissionDeniedError('Team context is required to resolve credentials.');
        }

        try {
            $credentials = $this->credentialResolver->resolveOrFail($teamId, $provider);
        } catch (\RuntimeException $e) {
            throw $e;
        }

        $useSync = (bool) ($request->get('use_sync', true));
        $timeout = (int) ($request->get('timeout_seconds', 90));
        $input = is_array($request->get('input')) ? $request->get('input') : [];

        $job = new ComputeJobDTO(
            provider: $provider,
            endpointId: $endpointId,
            input: $input,
            credentials: $credentials,
            timeoutSeconds: $timeout,
            useSync: $useSync,
        );

        try {
            $providerInstance = $this->manager->driver($provider);
            $result = $providerInstance->runSync($job);
        } catch (\Throwable $e) {
            throw $e;
        }

        return Response::text(json_encode([
            'provider' => $provider,
            'endpoint_id' => $endpointId,
            'status' => $result->status,
            'job_id' => $result->jobId,
            'output' => $result->output,
            'error' => $result->error,
            'duration_ms' => $result->durationMs,
        ]));
    }
}
