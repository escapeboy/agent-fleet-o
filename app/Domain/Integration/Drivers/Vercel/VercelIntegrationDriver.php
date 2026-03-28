<?php

namespace App\Domain\Integration\Drivers\Vercel;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class VercelIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.vercel.com';

    public function key(): string
    {
        return 'vercel';
    }

    public function label(): string
    {
        return 'Vercel';
    }

    public function description(): string
    {
        return 'Deploy projects to Vercel, monitor deployments, and trigger rollbacks.';
    }

    public function authType(): AuthType
    {
        return AuthType::BearerToken;
    }

    public function credentialSchema(): array
    {
        return [
            'token' => ['type' => 'string', 'required' => true, 'label' => 'API Token'],
            'team_id' => ['type' => 'string', 'required' => false, 'label' => 'Team ID (optional)'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/v2/user');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('token');

        if (! $token) {
            return HealthResult::fail('No API token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken((string) $token)->timeout(10)->get(self::API_BASE.'/v2/user');
            $latency = (int) ((microtime(true) - $start) * 1000);

            return $response->successful()
                ? HealthResult::ok($latency)
                : HealthResult::fail("HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('deployment.created', 'Deployment Created', 'A new deployment has been created.'),
            new TriggerDefinition('deployment.succeeded', 'Deployment Succeeded', 'A deployment has completed successfully.'),
            new TriggerDefinition('deployment.error', 'Deployment Failed', 'A deployment has failed.'),
            new TriggerDefinition('deployment.canceled', 'Deployment Canceled', 'A deployment has been canceled.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('deploy', 'Trigger Deploy', 'Trigger a new deployment for a Vercel project.', [
                'project_id' => ['type' => 'string', 'required' => true],
                'target' => ['type' => 'string', 'required' => false, 'enum' => ['preview', 'production']],
                'git_branch' => ['type' => 'string', 'required' => false],
            ]),
            new ActionDefinition('get_deployment', 'Get Deployment', 'Get the status and URL of a deployment.', [
                'deployment_id' => ['type' => 'string', 'required' => true],
            ]),
            new ActionDefinition('list_deployments', 'List Deployments', 'List recent deployments for a project.', [
                'project_id' => ['type' => 'string', 'required' => true],
                'limit' => ['type' => 'integer', 'required' => false],
            ]),
            new ActionDefinition('cancel_deployment', 'Cancel Deployment', 'Cancel an in-progress deployment.', [
                'deployment_id' => ['type' => 'string', 'required' => true],
            ]),
            new ActionDefinition('rollback', 'Rollback Deployment', 'Roll back a project to a previous deployment.', [
                'project_id' => ['type' => 'string', 'required' => true],
                'deployment_id' => ['type' => 'string', 'required' => true],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 0;
    }

    public function poll(Integration $integration): array
    {
        return [];
    }

    public function supportsWebhooks(): bool
    {
        return false;
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return false;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('token');
        $teamId = $integration->getCredentialSecret('team_id');

        return match ($action) {
            'deploy' => $this->deploy($token, $params, $teamId),
            'get_deployment' => $this->getDeployment($token, $params, $teamId),
            'list_deployments' => $this->listDeployments($token, $params, $teamId),
            'cancel_deployment' => $this->cancelDeployment($token, $params, $teamId),
            'rollback' => $this->rollback($token, $params, $teamId),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function http(?string $token, ?string $teamId = null): PendingRequest
    {
        $request = Http::withToken((string) $token)
            ->baseUrl(self::API_BASE)
            ->timeout(30)
            ->acceptJson();

        if ($teamId) {
            $request = $request->withQueryParameters(['teamId' => $teamId]);
        }

        return $request;
    }

    private function deploy(?string $token, array $params, ?string $teamId): array
    {
        $response = $this->http($token, $teamId)->post('/v13/deployments', array_filter([
            'name' => $params['project_id'],
            'target' => $params['target'] ?? 'preview',
            'gitSource' => isset($params['git_branch']) ? ['ref' => $params['git_branch']] : null,
        ]));

        $response->throw();

        $d = $response->json();

        return [
            'deployment_id' => $d['id'],
            'url' => $d['url'],
            'state' => $d['readyState'] ?? 'BUILDING',
        ];
    }

    private function getDeployment(?string $token, array $params, ?string $teamId): array
    {
        $response = $this->http($token, $teamId)->get("/v13/deployments/{$params['deployment_id']}");
        $response->throw();

        $d = $response->json();

        return [
            'deployment_id' => $d['id'],
            'url' => $d['url'],
            'state' => $d['readyState'] ?? $d['state'] ?? 'UNKNOWN',
            'created_at' => $d['createdAt'] ?? null,
            'ready' => ($d['readyState'] ?? '') === 'READY',
        ];
    }

    private function listDeployments(?string $token, array $params, ?string $teamId): array
    {
        $query = ['projectId' => $params['project_id'], 'limit' => $params['limit'] ?? 10];

        if ($teamId) {
            $query['teamId'] = $teamId;
        }

        $response = Http::withToken((string) $token)
            ->baseUrl(self::API_BASE)
            ->timeout(15)
            ->acceptJson()
            ->get('/v6/deployments', $query);

        $response->throw();

        return collect($response->json('deployments', []))->map(fn ($d) => [
            'deployment_id' => $d['uid'],
            'url' => $d['url'],
            'state' => $d['readyState'] ?? 'UNKNOWN',
            'created_at' => $d['createdAt'] ?? null,
        ])->toArray();
    }

    private function cancelDeployment(?string $token, array $params, ?string $teamId): array
    {
        $response = $this->http($token, $teamId)->patch("/v12/deployments/{$params['deployment_id']}/cancel");
        $response->throw();

        return ['canceled' => true, 'deployment_id' => $params['deployment_id']];
    }

    private function rollback(?string $token, array $params, ?string $teamId): array
    {
        $response = $this->http($token, $teamId)->post("/v9/projects/{$params['project_id']}/rollback/{$params['deployment_id']}");
        $response->throw();

        return ['rolled_back' => true, 'deployment_id' => $params['deployment_id']];
    }
}
