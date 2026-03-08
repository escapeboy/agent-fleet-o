<?php

namespace App\Domain\Integration\Drivers\Salesforce;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Salesforce CRM integration driver (OAuth2, polling-based).
 *
 * Auth: OAuth2 Authorization Code flow via Connected App.
 * instance_url is unique per org — NEVER hardcode API calls to login.salesforce.com.
 * Phase 1: polling only (no Change Data Capture / Streaming API).
 */
class SalesforceIntegrationDriver implements IntegrationDriverInterface
{
    private const API_VERSION = 'v59.0';

    public function key(): string
    {
        return 'salesforce';
    }

    public function label(): string
    {
        return 'Salesforce';
    }

    public function description(): string
    {
        return 'Sync Salesforce leads, opportunities, and cases. Trigger on CRM changes and execute actions from agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'password', 'required' => true,  'label' => 'Access Token'],
            'refresh_token' => ['type' => 'password', 'required' => false, 'label' => 'Refresh Token'],
            'instance_url' => ['type' => 'string',   'required' => true,  'label' => 'Instance URL',
                'hint' => 'e.g. https://myorg.my.salesforce.com — from OAuth callback'],
            'token_type' => ['type' => 'string',   'required' => false, 'label' => 'Token Type'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;
        $instanceUrl = $credentials['instance_url'] ?? null;

        if (! $token || ! $instanceUrl) {
            return false;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$instanceUrl}/services/data/".self::API_VERSION.'/');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('access_token');
        $instanceUrl = $integration->credential?->secret_data['instance_url']
            ?? $integration->config['instance_url'] ?? null;

        if (! $token || ! $instanceUrl) {
            return HealthResult::fail('Access token or instance URL not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get("{$instanceUrl}/services/data/".self::API_VERSION.'/');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return HealthResult::ok($latency, "Connected to {$instanceUrl}");
            }

            return HealthResult::fail($response->json('0.errorCode') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('new_lead', 'New Lead', 'A new Salesforce lead was created.'),
            new TriggerDefinition('opportunity_stage_change', 'Opportunity Stage Change', 'An opportunity stage was updated.'),
            new TriggerDefinition('new_case', 'New Case', 'A new support case was opened.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('create_lead', 'Create Lead', 'Create a new Salesforce lead.', [
                'first_name' => ['type' => 'string', 'required' => false, 'label' => 'First name'],
                'last_name' => ['type' => 'string', 'required' => true,  'label' => 'Last name'],
                'email' => ['type' => 'string', 'required' => false, 'label' => 'Email'],
                'company' => ['type' => 'string', 'required' => true,  'label' => 'Company'],
                'phone' => ['type' => 'string', 'required' => false, 'label' => 'Phone'],
            ]),
            new ActionDefinition('create_task', 'Create Task', 'Create a Salesforce activity task.', [
                'subject' => ['type' => 'string', 'required' => true,  'label' => 'Task subject'],
                'who_id' => ['type' => 'string', 'required' => false, 'label' => 'Related contact or lead ID'],
                'activity_date' => ['type' => 'string', 'required' => false, 'label' => 'Due date (YYYY-MM-DD)'],
                'description' => ['type' => 'string', 'required' => false, 'label' => 'Task description'],
            ]),
            new ActionDefinition('update_opportunity', 'Update Opportunity', 'Update a Salesforce opportunity record.', [
                'id' => ['type' => 'string', 'required' => true,  'label' => 'Opportunity ID'],
                'stage_name' => ['type' => 'string', 'required' => false, 'label' => 'Stage name'],
                'amount' => ['type' => 'number', 'required' => false, 'label' => 'Amount'],
            ]),
            new ActionDefinition('add_chatter_post', 'Add Chatter Post', 'Post to an object\'s Chatter feed.', [
                'parent_id' => ['type' => 'string', 'required' => true, 'label' => 'Record ID (opportunity, lead, etc.)'],
                'body' => ['type' => 'string', 'required' => true, 'label' => 'Post body'],
            ]),
        ];
    }

    public function pollFrequency(): int
    {
        return 300;
    }

    public function poll(Integration $integration): array
    {
        $token = $integration->getCredentialSecret('access_token');
        $instanceUrl = $integration->credential?->secret_data['instance_url']
            ?? $integration->config['instance_url'] ?? null;

        if (! $token || ! $instanceUrl) {
            return [];
        }

        $cursor = $integration->config['poll_cursor'] ?? now()->subMinutes(6)->format('Y-m-d\TH:i:s\Z');
        $signals = [];

        try {
            // Poll new leads
            $leadsResponse = Http::withToken($token)->timeout(15)
                ->get("{$instanceUrl}/services/data/".self::API_VERSION.'/query', [
                    'q' => "SELECT Id,Name,Email,Company,CreatedDate FROM Lead WHERE CreatedDate > {$cursor} ORDER BY CreatedDate LIMIT 50",
                ]);

            if ($leadsResponse->successful()) {
                foreach ($leadsResponse->json('records') ?? [] as $lead) {
                    $signals[] = [
                        'source_type' => 'salesforce',
                        'source_id' => 'sf:'.$lead['Id'],
                        'payload' => $lead,
                        'tags' => ['salesforce', 'new_lead'],
                    ];
                }
            }

            // Poll new cases
            $casesResponse = Http::withToken($token)->timeout(15)
                ->get("{$instanceUrl}/services/data/".self::API_VERSION.'/query', [
                    'q' => "SELECT Id,Subject,Status,Priority,CreatedDate FROM Case WHERE CreatedDate > {$cursor} ORDER BY CreatedDate LIMIT 50",
                ]);

            if ($casesResponse->successful()) {
                foreach ($casesResponse->json('records') ?? [] as $case) {
                    $signals[] = [
                        'source_type' => 'salesforce',
                        'source_id' => 'sf:'.$case['Id'],
                        'payload' => $case,
                        'tags' => ['salesforce', 'new_case'],
                    ];
                }
            }

            // Update cursor to now
            $integration->update(['config' => array_merge($integration->config ?? [], [
                'poll_cursor' => now()->format('Y-m-d\TH:i:s\Z'),
            ])]);
        } catch (\Throwable) {
            // Polling errors are non-fatal
        }

        return $signals;
    }

    public function supportsWebhooks(): bool
    {
        return false; // Phase 1: polling only
    }

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true; // Not applicable for Phase 1
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token');
        $instanceUrl = $integration->credential?->secret_data['instance_url']
            ?? $integration->config['instance_url'] ?? null;

        abort_unless($token && $instanceUrl, 422, 'Salesforce credentials not configured.');

        $base = "{$instanceUrl}/services/data/".self::API_VERSION;

        return match ($action) {
            'create_lead' => Http::withToken($token)->timeout(15)
                ->post("{$base}/sobjects/Lead/", array_filter([
                    'FirstName' => $params['first_name'] ?? null,
                    'LastName' => $params['last_name'],
                    'Email' => $params['email'] ?? null,
                    'Company' => $params['company'],
                    'Phone' => $params['phone'] ?? null,
                ]))->json(),

            'create_task' => Http::withToken($token)->timeout(15)
                ->post("{$base}/sobjects/Task/", array_filter([
                    'Subject' => $params['subject'],
                    'WhoId' => $params['who_id'] ?? null,
                    'ActivityDate' => $params['activity_date'] ?? null,
                    'Description' => $params['description'] ?? null,
                    'Status' => 'Not Started',
                ]))->json(),

            'update_opportunity' => Http::withToken($token)->timeout(15)
                ->patch("{$base}/sobjects/Opportunity/{$params['id']}", array_filter([
                    'StageName' => $params['stage_name'] ?? null,
                    'Amount' => isset($params['amount']) ? (float) $params['amount'] : null,
                ]))->json(),

            'add_chatter_post' => Http::withToken($token)->timeout(15)
                ->post("{$base}/chatter/feed-elements/", [
                    'subjectId' => $params['parent_id'],
                    'feedElementType' => 'FeedItem',
                    'body' => ['messageSegments' => [['type' => 'Text', 'text' => $params['body']]]],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }
}
