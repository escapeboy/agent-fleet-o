<?php

namespace App\Domain\Integration\Drivers\Confluence;

use App\Domain\Integration\Concerns\ChecksIntegrationResponse;
use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Confluence integration driver.
 *
 * Atlassian Confluence knowledge base. Uses Basic auth (email:api_token).
 * Webhooks use URL-embedded secret (no HMAC). Supports polling.
 */
class ConfluenceIntegrationDriver implements IntegrationDriverInterface
{
    use ChecksIntegrationResponse;

    public function key(): string
    {
        return 'confluence';
    }

    public function label(): string
    {
        return 'Confluence';
    }

    public function description(): string
    {
        return 'Search Confluence pages, receive page and comment events, and create content from agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'base_url' => ['type' => 'string', 'required' => true, 'label' => 'Confluence URL',
                'hint' => 'https://yourcompany.atlassian.net/wiki'],
            'email' => ['type' => 'string', 'required' => true, 'label' => 'Email'],
            'api_token' => ['type' => 'password', 'required' => true, 'label' => 'API Token',
                'hint' => 'https://id.atlassian.com/manage-profile/security/api-tokens'],
            'space_key' => ['type' => 'string', 'required' => false, 'label' => 'Space Key',
                'hint' => 'Optional — restrict polling to a specific space'],
        ];
    }

    private function apiBase(Integration|array $source): string
    {
        $url = $source instanceof Integration
            ? ($source->config['base_url'] ?? $source->getCredentialSecret('base_url') ?? '')
            : ($source['base_url'] ?? '');

        return rtrim((string) $url, '/').'/rest/api';
    }

    private function basicAuth(Integration|array $source): string
    {
        [$email, $token] = $source instanceof Integration
            ? [$source->getCredentialSecret('email'), $source->getCredentialSecret('api_token')]
            : [$source['email'] ?? '', $source['api_token'] ?? ''];

        return base64_encode("{$email}:{$token}");
    }

    public function validateCredentials(array $credentials): bool
    {
        if (empty($credentials['base_url']) || empty($credentials['email']) || empty($credentials['api_token'])) {
            return false;
        }

        try {
            $base = rtrim($credentials['base_url'], '/').'/rest/api';
            $auth = base64_encode("{$credentials['email']}:{$credentials['api_token']}");

            return Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->timeout(10)
                ->get("{$base}/space?limit=1")
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $base = $this->apiBase($integration);
        $auth = $this->basicAuth($integration);

        $start = microtime(true);
        try {
            $response = Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->timeout(10)
                ->get("{$base}/space?limit=1");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency);
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('page_created', 'Page Created', 'A new Confluence page was created.'),
            new TriggerDefinition('page_updated', 'Page Updated', 'A Confluence page was updated.'),
            new TriggerDefinition('comment_created', 'Comment Created', 'A comment was added to a Confluence page.'),
            new TriggerDefinition('blog_post_created', 'Blog Post Created', 'A new blog post was published.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_page', 'Create Page', 'Create a new Confluence page.', [
                'space_key' => ['type' => 'string', 'required' => true, 'label' => 'Space Key'],
                'title' => ['type' => 'string', 'required' => true, 'label' => 'Page title'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Page content (HTML or Confluence storage format)'],
                'parent_id' => ['type' => 'string', 'required' => false, 'label' => 'Parent page ID'],
            ]),
            new ActionDefinition('update_page', 'Update Page', 'Update an existing Confluence page.', [
                'page_id' => ['type' => 'string', 'required' => true, 'label' => 'Page ID'],
                'title' => ['type' => 'string', 'required' => true, 'label' => 'New title'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'New content'],
                'version' => ['type' => 'string', 'required' => true, 'label' => 'Current version number'],
            ]),
            new ActionDefinition('add_comment', 'Add Comment', 'Add a comment to a Confluence page.', [
                'page_id' => ['type' => 'string', 'required' => true, 'label' => 'Page ID'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Comment text'],
            ]),
            new ActionDefinition('search_content', 'Search Content', 'Search Confluence using CQL.', [
                'cql' => ['type' => 'string', 'required' => true, 'label' => 'CQL query'],
                'limit' => ['type' => 'string', 'required' => false, 'label' => 'Max results (default 10)'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        $base = $this->apiBase($integration);
        $auth = $this->basicAuth($integration);
        $spaceKey = $integration->config['space_key'] ?? $integration->getCredentialSecret('space_key');

        $params = ['type' => 'page', 'orderby' => 'modified', 'limit' => 25];
        if ($spaceKey) {
            $params['spaceKey'] = $spaceKey;
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Basic {$auth}"])
                ->timeout(15)
                ->get("{$base}/content", $params);

            if (! $response->successful()) {
                return [];
            }

            return array_map(fn ($page) => [
                'source_type' => 'confluence',
                'source_id' => 'confluence:'.$page['id'],
                'payload' => $page,
                'tags' => ['confluence', 'page', $page['space']['key'] ?? ''],
            ], $response->json('results') ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    /**
     * Confluence webhooks do not support HMAC. Returns true (permissive).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $event = $payload['webhookEvent'] ?? 'page_created';
        $pageId = $payload['page']['id'] ?? $payload['comment']['container']['id'] ?? uniqid('cf_', true);

        $trigger = match ($event) {
            'page_created' => 'page_created',
            'page_updated' => 'page_updated',
            'comment_created' => 'comment_created',
            'blogpost_created' => 'blog_post_created',
            default => str_replace('page_', 'page_', $event),
        };

        return [
            [
                'source_type' => 'confluence',
                'source_id' => 'confluence:'.$pageId,
                'payload' => $payload,
                'tags' => ['confluence', $trigger],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $base = $this->apiBase($integration);
        $auth = $this->basicAuth($integration);
        $http = Http::withHeaders(['Authorization' => "Basic {$auth}"])->timeout(15);

        return match ($action) {
            'create_page' => $this->checked($http->post("{$base}/content", [
                'type' => 'page',
                'title' => $params['title'],
                'space' => ['key' => $params['space_key']],
                'ancestors' => isset($params['parent_id']) ? [['id' => $params['parent_id']]] : [],
                'body' => ['storage' => ['value' => $params['body'], 'representation' => 'storage']],
            ]))->json(),

            'update_page' => $this->checked($http->put("{$base}/content/{$params['page_id']}", [
                'version' => ['number' => (int) $params['version']],
                'title' => $params['title'],
                'type' => 'page',
                'body' => ['storage' => ['value' => $params['body'], 'representation' => 'storage']],
            ]))->json(),

            'add_comment' => $this->checked($http->post("{$base}/content", [
                'type' => 'comment',
                'container' => ['id' => $params['page_id'], 'type' => 'page'],
                'body' => ['storage' => ['value' => $params['body'], 'representation' => 'storage']],
            ]))->json(),

            'search_content' => $this->checked($http->get("{$base}/content/search", [
                'cql' => $params['cql'],
                'limit' => (int) ($params['limit'] ?? 10),
            ]))->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
