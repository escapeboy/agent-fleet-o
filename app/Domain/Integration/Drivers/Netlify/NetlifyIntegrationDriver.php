<?php

namespace App\Domain\Integration\Drivers\Netlify;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class NetlifyIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.netlify.com/api/v1';

    public function key(): string
    {
        return 'netlify';
    }

    public function label(): string
    {
        return 'Netlify';
    }

    public function description(): string
    {
        return 'Deploy sites to Netlify, trigger builds, and manage deployments.';
    }

    public function authType(): AuthType
    {
        return AuthType::BearerToken;
    }

    public function credentialSchema(): array
    {
        return [
            'token' => ['type' => 'string', 'required' => true, 'label' => 'Personal Access Token'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/user');

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
            $response = Http::withToken((string) $token)->timeout(10)->get(self::API_BASE.'/user');
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
            new TriggerDefinition('deploy_building', 'Deploy Building', 'A deploy has started building.'),
            new TriggerDefinition('deploy_created', 'Deploy Created', 'A deploy has been created and is live.'),
            new TriggerDefinition('deploy_failed', 'Deploy Failed', 'A deploy has failed.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('trigger_build', 'Trigger Build', 'Trigger a new build for a Netlify site.', [
                'site_id' => ['type' => 'string', 'required' => true],
            ]),
            new ActionDefinition('get_deploy', 'Get Deploy', 'Get the status of a specific deploy.', [
                'deploy_id' => ['type' => 'string', 'required' => true],
            ]),
            new ActionDefinition('list_deploys', 'List Deploys', 'List recent deploys for a site.', [
                'site_id' => ['type' => 'string', 'required' => true],
                'per_page' => ['type' => 'integer', 'required' => false],
            ]),
            new ActionDefinition('cancel_deploy', 'Cancel Deploy', 'Cancel an in-progress deploy.', [
                'deploy_id' => ['type' => 'string', 'required' => true],
            ]),
            new ActionDefinition('publish_deploy', 'Publish Deploy', 'Publish (restore) a previous deploy as the current live site.', [
                'deploy_id' => ['type' => 'string', 'required' => true],
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

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('token');

        return match ($action) {
            'trigger_build' => $this->triggerBuild($token, $params),
            'get_deploy' => $this->getDeploy($token, $params),
            'list_deploys' => $this->listDeploys($token, $params),
            'cancel_deploy' => $this->cancelDeploy($token, $params),
            'publish_deploy' => $this->publishDeploy($token, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function http(?string $token): PendingRequest
    {
        return Http::withToken((string) $token)
            ->baseUrl(self::API_BASE)
            ->timeout(30)
            ->acceptJson();
    }

    private function triggerBuild(?string $token, array $params): array
    {
        $response = $this->http($token)->post("/sites/{$params['site_id']}/builds");
        $response->throw();

        $build = $response->json();

        return [
            'deploy_id' => $build['deploy_id'] ?? $build['id'] ?? null,
            'state' => $build['state'] ?? 'building',
        ];
    }

    private function getDeploy(?string $token, array $params): array
    {
        $response = $this->http($token)->get("/deploys/{$params['deploy_id']}");
        $response->throw();

        $d = $response->json();

        return [
            'deploy_id' => $d['id'],
            'url' => $d['deploy_ssl_url'] ?? $d['deploy_url'] ?? '',
            'state' => $d['state'],
            'created_at' => $d['created_at'] ?? null,
            'ready' => $d['state'] === 'ready',
            'error_message' => $d['error_message'] ?? null,
        ];
    }

    private function listDeploys(?string $token, array $params): array
    {
        $response = $this->http($token)->get("/sites/{$params['site_id']}/deploys", [
            'per_page' => $params['per_page'] ?? 10,
        ]);

        $response->throw();

        return collect($response->json())->map(fn ($d) => [
            'deploy_id' => $d['id'],
            'url' => $d['deploy_ssl_url'] ?? $d['deploy_url'] ?? '',
            'state' => $d['state'],
            'created_at' => $d['created_at'] ?? null,
        ])->toArray();
    }

    private function cancelDeploy(?string $token, array $params): array
    {
        $response = $this->http($token)->post("/deploys/{$params['deploy_id']}/cancel");
        $response->throw();

        return ['canceled' => true, 'deploy_id' => $params['deploy_id']];
    }

    private function publishDeploy(?string $token, array $params): array
    {
        $response = $this->http($token)->post("/deploys/{$params['deploy_id']}/restore");
        $response->throw();

        $d = $response->json();

        return [
            'published' => true,
            'deploy_id' => $d['id'],
            'url' => $d['deploy_ssl_url'] ?? $d['deploy_url'] ?? '',
        ];
    }
}
