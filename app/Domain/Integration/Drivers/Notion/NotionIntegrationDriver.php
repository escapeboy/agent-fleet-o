<?php

namespace App\Domain\Integration\Drivers\Notion;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class NotionIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://api.notion.com/v1';

    private const API_VERSION = '2022-06-28';

    public function key(): string
    {
        return 'notion';
    }

    public function label(): string
    {
        return 'Notion';
    }

    public function description(): string
    {
        return 'Poll Notion databases for new or updated pages and create/update pages from AI output.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'string', 'required' => true, 'label' => 'Integration Token', 'hint' => 'notion.so → Settings → Integrations'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Notion-Version' => self::API_VERSION])
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token');

        if (! $token) {
            return HealthResult::fail('No access token configured.');
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Notion-Version' => self::API_VERSION])
                ->timeout(10)
                ->get(self::API_BASE.'/users/me');
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
            new TriggerDefinition('page_updated', 'Page Updated', 'A Notion page was recently updated (polled).'),
            new TriggerDefinition('database_entry_created', 'Database Entry Created', 'A new row was added to a database (polled).'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_page', 'Create Page', 'Create a new page in a Notion database.', [
                'database_id' => ['type' => 'string', 'required' => true],
                'title' => ['type' => 'string', 'required' => true],
                'properties' => ['type' => 'object', 'required' => false],
            ]),
            new ActionDefinition('append_block', 'Append Block', 'Append content blocks to a Notion page.', [
                'page_id' => ['type' => 'string', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        /** @var array<string, mixed> $config */
        $config = $integration->config ?? [];
        $databaseId = $config['database_id'] ?? null;
        $token = $integration->getCredentialSecret('access_token');

        if (! $databaseId || ! $token) {
            return [];
        }

        $lastCursor = $config['last_cursor'] ?? null;
        $filter = array_filter([
            'start_cursor' => $lastCursor,
            'page_size' => 20,
        ]);

        try {
            $response = Http::withToken($token)
                ->withHeaders(['Notion-Version' => self::API_VERSION])
                ->post(self::API_BASE."/databases/{$databaseId}/query", $filter);

            if (! $response->successful()) {
                return [];
            }

            $pages = $response->json('results', []);
            $nextCursor = $response->json('next_cursor');

            if ($nextCursor) {
                $config['last_cursor'] = $nextCursor;
                $integration->update(['config' => $config]);
            }

            return array_map(fn ($page) => [
                'source_type' => 'notion',
                'source_id' => $page['id'],
                'payload' => $page,
                'tags' => ['notion', 'page'],
            ], $pages);
        } catch (\Throwable) {
            return [];
        }
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
        $token = $integration->getCredentialSecret('access_token');

        return match ($action) {
            'create_page' => $this->createPage($token, $params),
            'append_block' => $this->appendBlock($token, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function createPage(?string $token, array $params): array
    {
        $response = Http::withToken((string) $token)
            ->withHeaders(['Notion-Version' => self::API_VERSION])
            ->post(self::API_BASE.'/pages', [
                'parent' => ['database_id' => $params['database_id']],
                'properties' => array_merge(
                    $params['properties'] ?? [],
                    ['title' => [['text' => ['content' => $params['title']]]]],
                ),
            ]);

        return $response->json();
    }

    private function appendBlock(?string $token, array $params): array
    {
        $response = Http::withToken((string) $token)
            ->withHeaders(['Notion-Version' => self::API_VERSION])
            ->patch(self::API_BASE."/blocks/{$params['page_id']}/children", [
                'children' => [
                    ['object' => 'block', 'type' => 'paragraph', 'paragraph' => ['rich_text' => [['type' => 'text', 'text' => ['content' => $params['content']]]]]],
                ],
            ]);

        return $response->json();
    }
}
