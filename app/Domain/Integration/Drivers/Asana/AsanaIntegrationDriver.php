<?php

namespace App\Domain\Integration\Drivers\Asana;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\Contracts\SubscribableConnectorInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\DTOs\WebhookRegistrationDTO;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\DTOs\SignalDTO;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Asana integration driver.
 *
 * Project management platform. Uses personal access tokens.
 * Signature: x-hook-signature — HMAC-SHA256 hex.
 * Implements SubscribableConnectorInterface. Note: Asana webhook registration
 * involves a challenge handshake — the target URL must respond with
 * X-Hook-Secret header echoed back.
 */
class AsanaIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    use ChecksIntegrationResponse;

    private const API_BASE = 'https://app.asana.com/api/1.0';

    public function key(): string
    {
        return 'asana';
    }

    public function label(): string
    {
        return 'Asana';
    }

    public function description(): string
    {
        return 'Receive Asana task and project events and manage tasks from agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [];
    }

    private function withAuth(Integration $integration): PendingRequest
    {
        return Http::withToken($integration->getCredentialSecret('access_token'))
            ->withHeaders(['Accept' => 'application/json'])
            ->timeout(15);
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            return Http::withToken($token)
                ->timeout(10)
                ->get(self::API_BASE.'/users/me')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token');

        if (! $token) {
            return HealthResult::fail('Personal access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/users/me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('errors.0.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('task_created', 'Task Created', 'A new task was created in Asana.'),
            new TriggerDefinition('task_completed', 'Task Completed', 'A task was marked as complete.'),
            new TriggerDefinition('task_assignee_changed', 'Assignee Changed', 'A task was assigned to a user.'),
            new TriggerDefinition('project_task_added', 'Task Added to Project', 'A task was added to an Asana project.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_task', 'Create Task', 'Create a new task in an Asana project.', [
                'project_id' => ['type' => 'string', 'required' => true, 'label' => 'Project GID'],
                'name' => ['type' => 'string', 'required' => true, 'label' => 'Task name'],
                'notes' => ['type' => 'string', 'required' => false, 'label' => 'Task description'],
                'assignee' => ['type' => 'string', 'required' => false, 'label' => 'Assignee email or GID'],
            ]),
            new ActionDefinition('complete_task', 'Complete Task', 'Mark a task as complete.', [
                'task_id' => ['type' => 'string', 'required' => true, 'label' => 'Task GID'],
            ]),
            new ActionDefinition('add_follower', 'Add Follower', 'Add a follower to an Asana task.', [
                'task_id' => ['type' => 'string', 'required' => true, 'label' => 'Task GID'],
                'user' => ['type' => 'string', 'required' => true, 'label' => 'User email or GID'],
            ]),
            new ActionDefinition('update_task', 'Update Task', 'Update task notes or due date.', [
                'task_id' => ['type' => 'string', 'required' => true, 'label' => 'Task GID'],
                'notes' => ['type' => 'string', 'required' => false, 'label' => 'Notes'],
                'due_on' => ['type' => 'string', 'required' => false, 'label' => 'Due date (YYYY-MM-DD)'],
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
        return true;
    }

    /**
     * Asana signature: x-hook-signature header — HMAC-SHA256 hex.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-hook-signature'] ?? '';

        if ($sig === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $events = $payload['events'] ?? [];

        if (empty($events)) {
            return [];
        }

        return array_map(function ($event) {
            $resourceType = $event['resource']['resource_type'] ?? 'task';
            $resourceId = $event['resource']['gid'] ?? uniqid('asana_', true);
            $action = $event['action'] ?? 'changed';

            $trigger = match ("{$resourceType}.{$action}") {
                'task.added' => 'task_created',
                'task.changed' => 'task_completed',
                default => "{$resourceType}_{$action}",
            };

            return [
                'source_type' => 'asana',
                'source_id' => "asana:{$resourceId}",
                'payload' => $event,
                'tags' => ['asana', $trigger, $resourceType],
            ];
        }, $events);
    }

    // SubscribableConnectorInterface

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $resourceId = $filterConfig['resource_id'] ?? ''; // project GID or workspace GID

        $response = $this->withAuth($integration)
            ->post(self::API_BASE.'/webhooks', ['data' => [
                'resource' => $resourceId,
                'target' => $callbackUrl,
            ]]);

        $response->throw();

        // The secret comes back in x-hook-secret header on the handshake request,
        // not in the registration response. Return a generated secret here;
        // the actual secret will be captured by the webhook controller during handshake.
        return new WebhookRegistrationDTO(
            webhookId: $response->json('data.gid'),
            webhookSecret: Str::random(40),
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $this->withAuth($integration)
            ->delete(self::API_BASE."/webhooks/{$webhookId}");
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        return $this->verifyWebhookSignature($rawBody, $headers, $webhookSecret);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $events = $payload['events'] ?? [];

        if (empty($events)) {
            return null;
        }

        $event = $events[0];
        $resourceId = $event['resource']['gid'] ?? uniqid('asana_', true);
        $action = $event['action'] ?? 'changed';
        $type = $event['resource']['resource_type'] ?? 'task';

        return new SignalDTO(
            sourceIdentifier: "asana:{$resourceId}",
            sourceNativeId: "asana.{$type}.{$action}.{$resourceId}",
            payload: $payload,
            tags: ['asana', "{$type}_{$action}"],
        );
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $http = $this->withAuth($integration);

        return match ($action) {
            'create_task' => $this->checked($http->post(self::API_BASE.'/tasks', ['data' => array_filter([
                'name' => $params['name'],
                'notes' => $params['notes'] ?? null,
                'assignee' => $params['assignee'] ?? null,
                'projects' => [$params['project_id']],
            ])]))->json(),

            'complete_task' => $this->checked($http->put(self::API_BASE."/tasks/{$params['task_id']}", [
                'data' => ['completed' => true],
            ]))->json(),

            'add_follower' => $this->checked($http->post(self::API_BASE."/tasks/{$params['task_id']}/addFollowers", [
                'data' => ['followers' => [$params['user']]],
            ]))->json(),

            'update_task' => $this->checked($http->put(self::API_BASE."/tasks/{$params['task_id']}", [
                'data' => array_filter([
                    'notes' => $params['notes'] ?? null,
                    'due_on' => $params['due_on'] ?? null,
                ]),
            ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
