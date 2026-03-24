<?php

namespace App\Domain\Integration\Drivers\ClickUp;

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
 * ClickUp integration driver.
 *
 * Project management platform. Webhooks use HMAC-MD5 signature.
 * Note: ClickUp's Authorization header does NOT use a Bearer prefix.
 * Implements SubscribableConnectorInterface for programmatic webhook registration.
 */
class ClickUpIntegrationDriver implements IntegrationDriverInterface, SubscribableConnectorInterface
{
    use ChecksIntegrationResponse;

    private const API_BASE = 'https://api.clickup.com/api/v2';

    public function key(): string
    {
        return 'clickup';
    }

    public function label(): string
    {
        return 'ClickUp';
    }

    public function description(): string
    {
        return 'Receive ClickUp task events and manage tasks, statuses, and comments from agent workflows.';
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
        // ClickUp uses Authorization header without Bearer prefix
        return Http::withHeaders(['Authorization' => ($integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('api_token'))])
            ->timeout(15);
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? $credentials['api_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            return Http::withHeaders(['Authorization' => $token])
                ->timeout(10)
                ->get(self::API_BASE.'/user')
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('api_token');

        if (! $token) {
            return HealthResult::fail('API token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders(['Authorization' => $token])
                ->timeout(10)
                ->get(self::API_BASE.'/user');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('err') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('task_created', 'Task Created', 'A new task was created in ClickUp.'),
            new TriggerDefinition('task_updated', 'Task Updated', 'A task was updated.'),
            new TriggerDefinition('task_status_changed', 'Status Changed', 'A task status was changed.'),
            new TriggerDefinition('task_deleted', 'Task Deleted', 'A task was deleted.'),
            new TriggerDefinition('comment_created', 'Comment Created', 'A comment was added to a task.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_task', 'Create Task', 'Create a new task in a ClickUp list.', [
                'list_id' => ['type' => 'string', 'required' => true, 'label' => 'List ID'],
                'name' => ['type' => 'string', 'required' => true, 'label' => 'Task name'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Task description'],
                'priority' => ['type' => 'string', 'required' => false, 'label' => 'Priority: 1=urgent, 2=high, 3=normal, 4=low'],
            ]),
            new ActionDefinition('update_task_status', 'Update Status', 'Change the status of a ClickUp task.', [
                'task_id' => ['type' => 'string', 'required' => true, 'label' => 'Task ID'],
                'status' => ['type' => 'string', 'required' => true, 'label' => 'Status name (e.g. in progress, complete)'],
            ]),
            new ActionDefinition('post_comment', 'Post Comment', 'Add a comment to a ClickUp task.', [
                'task_id' => ['type' => 'string', 'required' => true, 'label' => 'Task ID'],
                'comment_text' => ['type' => 'string', 'required' => true, 'label' => 'Comment text'],
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
     * ClickUp signature: x-signature header — raw hex HMAC-MD5 of rawBody.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $sig = $headers['x-signature'] ?? '';

        if ($sig === '') {
            return false;
        }

        return hash_equals(hash_hmac('md5', $rawBody, $secret), $sig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $event = $payload['event'] ?? 'taskCreated';
        $taskId = $payload['task_id'] ?? uniqid('cu_', true);
        $historyId = $payload['history_items'][0]['id'] ?? $taskId;

        $trigger = match ($event) {
            'taskCreated' => 'task_created',
            'taskUpdated' => 'task_updated',
            'taskStatusUpdated' => 'task_status_changed',
            'taskDeleted' => 'task_deleted',
            'taskCommentPosted' => 'comment_created',
            default => Str::snake($event),
        };

        return [
            [
                'source_type' => 'clickup',
                'source_id' => 'clickup:'.$historyId,
                'payload' => $payload,
                'tags' => ['clickup', $trigger],
            ],
        ];
    }

    // SubscribableConnectorInterface

    public function registerWebhook(Integration $integration, array $filterConfig, string $callbackUrl): WebhookRegistrationDTO
    {
        $workspaceId = $filterConfig['workspace_id'] ?? '';
        $events = $filterConfig['events'] ?? ['taskCreated', 'taskUpdated', 'taskStatusUpdated', 'taskCommentPosted'];

        $response = $this->withAuth($integration)
            ->post(self::API_BASE."/team/{$workspaceId}/webhook", [
                'endpoint' => $callbackUrl,
                'events' => $events,
            ]);

        $response->throw();

        return new WebhookRegistrationDTO(
            webhookId: (string) $response->json('webhook.id'),
            webhookSecret: $response->json('webhook.secret') ?? Str::random(40),
        );
    }

    public function deregisterWebhook(Integration $integration, string $webhookId, array $filterConfig): void
    {
        $this->withAuth($integration)
            ->delete(self::API_BASE."/webhook/{$webhookId}");
    }

    public function verifySubscriptionSignature(string $rawBody, array $headers, string $webhookSecret): bool
    {
        return $this->verifyWebhookSignature($rawBody, $headers, $webhookSecret);
    }

    public function mapPayloadToSignalDTO(array $payload, array $headers, array $filterConfig): ?SignalDTO
    {
        $event = $payload['event'] ?? 'taskCreated';
        $taskId = $payload['task_id'] ?? uniqid('cu_', true);
        $trigger = Str::snake($event);

        return new SignalDTO(
            sourceIdentifier: "clickup:{$taskId}",
            sourceNativeId: "clickup.{$event}.{$taskId}",
            payload: $payload,
            tags: ['clickup', $trigger],
        );
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token') ?? $integration->getCredentialSecret('api_token');
        abort_unless($token, 422, 'ClickUp API token not configured.');

        $http = Http::withHeaders(['Authorization' => $token])->timeout(15);

        return match ($action) {
            'create_task' => $this->checked($http->post(self::API_BASE."/list/{$params['list_id']}/task", array_filter([
                'name' => $params['name'],
                'description' => $params['description'] ?? null,
                'priority' => isset($params['priority']) ? (int) $params['priority'] : null,
            ])))->json(),

            'update_task_status' => $this->checked($http->put(self::API_BASE."/task/{$params['task_id']}", [
                'status' => $params['status'],
            ]))->json(),

            'post_comment' => $this->checked($http->post(self::API_BASE."/task/{$params['task_id']}/comment", [
                'comment_text' => $params['comment_text'],
            ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
