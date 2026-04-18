<?php

namespace App\Mcp\Tools\RunPod;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\RunPod\RunPodClient;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool for managing RunPod GPU cloud integration.
 *
 * Actions:
 *   credential_save    — Save (or update) a RunPod API key for the team
 *   credential_check   — Verify the stored API key is valid
 *   credential_remove  — Remove the stored RunPod API key
 *   endpoint_run       — Run a job on a RunPod serverless endpoint
 *   endpoint_status    — Check status of an async job
 *   endpoint_health    — Check worker/queue health of a serverless endpoint
 *   pod_create         — Create a persistent GPU pod
 *   pod_list           — List all pods for the account
 *   pod_stop           — Stop a running pod
 */
#[IsDestructive]
#[AssistantTool('write')]
class RunPodManageTool extends Tool
{
    protected string $name = 'runpod_manage';

    protected string $description = 'Manage RunPod GPU cloud integration: save API keys, run serverless endpoint jobs, manage persistent pods. Actions: credential_save, credential_check, credential_remove, endpoint_run, endpoint_status, endpoint_health, pod_create, pod_list, pod_status, pod_stop.';

    public function __construct(
        private readonly RunPodClient $client,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action: credential_save | credential_check | credential_remove | endpoint_run | endpoint_status | endpoint_health | pod_create | pod_list | pod_status | pod_stop')
                ->required(),
            'api_key' => $schema->string()
                ->description('RunPod API key (required for credential_save)'),
            'endpoint_id' => $schema->string()
                ->description('Serverless endpoint ID (required for endpoint_run, endpoint_status, endpoint_health)'),
            'job_id' => $schema->string()
                ->description('Job ID returned by endpoint_run async mode (required for endpoint_status)'),
            'input' => $schema->object()
                ->description('Input payload for endpoint_run'),
            'use_sync' => $schema->boolean()
                ->description('Use synchronous mode for endpoint_run (default: true)'),
            'timeout_seconds' => $schema->integer()
                ->description('Max wait time for sync run (default: 90)'),
            'pod_id' => $schema->string()
                ->description('Pod ID for pod_status or pod_stop'),
            'pod_config' => $schema->object()
                ->description('Pod configuration for pod_create (imageName, gpuTypeIds, gpuCount, env, etc.)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $action = $request->get('action');
        $teamId = app('mcp.team_id') ?? null;

        return match ($action) {
            'credential_save' => $this->saveCredential($request, $teamId),
            'credential_check' => $this->checkCredential($teamId),
            'credential_remove' => $this->removeCredential($teamId),
            'endpoint_run' => $this->runEndpoint($request, $teamId),
            'endpoint_status' => $this->getEndpointStatus($request, $teamId),
            'endpoint_health' => $this->getEndpointHealth($request, $teamId),
            'pod_create' => $this->createPod($request, $teamId),
            'pod_list' => $this->listPods($teamId),
            'pod_status' => $this->getPodStatus($request, $teamId),
            'pod_stop' => $this->stopPod($request, $teamId),
            default => Response::error("Unknown action: {$action}. Valid: credential_save, credential_check, credential_remove, endpoint_run, endpoint_status, endpoint_health, pod_create, pod_list, pod_status, pod_stop"),
        };
    }

    private function saveCredential(Request $request, ?string $teamId): Response
    {
        $apiKey = $request->get('api_key');

        if (! $apiKey) {
            return Response::error('api_key is required for credential_save');
        }

        if (! $this->client->validateApiKey($apiKey)) {
            return Response::error('RunPod API key validation failed. Check your key at https://www.runpod.io/console/user/settings');
        }

        TeamProviderCredential::withoutGlobalScopes()->updateOrCreate(
            ['team_id' => $teamId, 'provider' => 'runpod'],
            ['credentials' => ['api_key' => $apiKey], 'is_active' => true],
        );

        return Response::text(json_encode([
            'status' => 'saved',
            'message' => 'RunPod API key validated and saved successfully.',
        ]));
    }

    private function checkCredential(?string $teamId): Response
    {
        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::text(json_encode([
                'configured' => false,
                'valid' => false,
                'message' => 'No RunPod API key configured.',
            ]));
        }

        $valid = $this->client->validateApiKey($apiKey);

        return Response::text(json_encode([
            'configured' => true,
            'valid' => $valid,
            'message' => $valid ? 'API key is valid.' : 'API key appears invalid or RunPod API is unreachable.',
        ]));
    }

    private function removeCredential(?string $teamId): Response
    {
        $deleted = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', 'runpod')
            ->delete();

        return Response::text(json_encode([
            'status' => $deleted ? 'removed' : 'not_found',
            'message' => $deleted ? 'RunPod API key removed.' : 'No RunPod credential found.',
        ]));
    }

    private function runEndpoint(Request $request, ?string $teamId): Response
    {
        $endpointId = $request->get('endpoint_id');

        if (! $endpointId) {
            return Response::error('endpoint_id is required for endpoint_run');
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured. Use runpod_manage with action=credential_save first.');
        }

        $input = $request->get('input', []);
        $useSync = (bool) ($request->get('use_sync', true));
        $timeout = (int) ($request->get('timeout_seconds', 90));

        try {
            if ($useSync) {
                $result = $this->client->runSync($endpointId, $input, $apiKey, $timeout);
            } else {
                $result = $this->client->run($endpointId, $input, $apiKey);
            }

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod endpoint_run failed: '.$e->getMessage());
        }
    }

    private function getEndpointStatus(Request $request, ?string $teamId): Response
    {
        $endpointId = $request->get('endpoint_id');
        $jobId = $request->get('job_id');

        if (! $endpointId || ! $jobId) {
            return Response::error('endpoint_id and job_id are required for endpoint_status');
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured.');
        }

        try {
            $result = $this->client->getStatus($endpointId, $jobId, $apiKey);

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod endpoint_status failed: '.$e->getMessage());
        }
    }

    private function getEndpointHealth(Request $request, ?string $teamId): Response
    {
        $endpointId = $request->get('endpoint_id');

        if (! $endpointId) {
            return Response::error('endpoint_id is required for endpoint_health');
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured.');
        }

        try {
            $result = $this->client->getHealth($endpointId, $apiKey);

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod endpoint_health failed: '.$e->getMessage());
        }
    }

    private function createPod(Request $request, ?string $teamId): Response
    {
        $podConfig = $request->get('pod_config');

        if (! is_array($podConfig) || empty($podConfig)) {
            return Response::error('pod_config is required for pod_create. Provide imageName, gpuTypeIds, etc.');
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured.');
        }

        try {
            $result = $this->client->createPod($podConfig, $apiKey);

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod pod_create failed: '.$e->getMessage());
        }
    }

    private function listPods(?string $teamId): Response
    {
        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured.');
        }

        try {
            $result = $this->client->listPods($apiKey);

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod pod_list failed: '.$e->getMessage());
        }
    }

    private function getPodStatus(Request $request, ?string $teamId): Response
    {
        $podId = $request->get('pod_id');

        if (! $podId) {
            return Response::error('pod_id is required for pod_status');
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured.');
        }

        try {
            $result = $this->client->getPod($podId, $apiKey);

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod pod_status failed: '.$e->getMessage());
        }
    }

    private function stopPod(Request $request, ?string $teamId): Response
    {
        $podId = $request->get('pod_id');

        if (! $podId) {
            return Response::error('pod_id is required for pod_stop');
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return Response::error('No RunPod API key configured.');
        }

        try {
            $result = $this->client->stopPod($podId, $apiKey);

            return Response::text(json_encode($result));
        } catch (\Throwable $e) {
            return Response::error('RunPod pod_stop failed: '.$e->getMessage());
        }
    }

    private function resolveApiKey(?string $teamId): ?string
    {
        if (! $teamId) {
            return null;
        }

        $credential = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', 'runpod')
            ->where('is_active', true)
            ->first();

        return $credential?->credentials['api_key'] ?? null;
    }
}
