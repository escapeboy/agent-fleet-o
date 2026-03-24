<?php

namespace App\Domain\Integration\Drivers\Mailchimp;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Mailchimp email marketing integration driver.
 *
 * Datacenter is extracted from the API key suffix: `abc123-us6` → datacenter = `us6`.
 * All API calls go to https://{dc}.api.mailchimp.com/3.0/
 *
 * Webhook note: Mailchimp sends a GET request for URL verification (returns 200).
 * Subsequent event payloads are application/x-www-form-urlencoded (not JSON).
 * The IntegrationWebhookController's existing GET challenge endpoint handles this.
 */
class MailchimpIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'mailchimp';
    }

    public function label(): string
    {
        return 'Mailchimp';
    }

    public function description(): string
    {
        return 'Add subscribers, manage audience tags, and trigger automations from AI agent workflows.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'api_key' => ['type' => 'password', 'required' => true, 'label' => 'API Key',
                'hint' => 'From Mailchimp → Account → Extras → API Keys. Datacenter (e.g. us6) is auto-extracted from the key.'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $accessToken = $credentials['access_token'] ?? null;
        $apiKey = $credentials['api_key'] ?? null;

        if (! $accessToken && ! $apiKey) {
            return false;
        }

        try {
            $base = $this->baseUrl($credentials);

            if ($accessToken) {
                $response = Http::withToken($accessToken)
                    ->timeout(10)
                    ->get("{$base}/");
            } else {
                $response = Http::withBasicAuth('anystring', $apiKey)
                    ->timeout(10)
                    ->get("{$base}/");
            }

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $accessToken = $integration->getCredentialSecret('access_token');
        $apiKey = $integration->getCredentialSecret('api_key');

        if (! $accessToken && ! $apiKey) {
            return HealthResult::fail('No credentials configured.');
        }

        $start = microtime(true);
        try {
            $base = $this->baseUrlFromIntegration($integration);

            $response = $accessToken
                ? Http::withToken($accessToken)->timeout(10)->get("{$base}/")
                : Http::withBasicAuth('anystring', $apiKey)->timeout(10)->get("{$base}/");

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $accountName = $response->json('account_name', 'Mailchimp');

                return HealthResult::ok($latency, "Connected as {$accountName}");
            }

            return HealthResult::fail($response->json('detail') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('subscribe', 'Subscriber Added', 'A new subscriber was added to a Mailchimp list.'),
            new TriggerDefinition('unsubscribe', 'Subscriber Removed', 'A subscriber unsubscribed from a list.'),
            new TriggerDefinition('profile', 'Profile Updated', 'A subscriber profile was updated.'),
            new TriggerDefinition('cleaned', 'Email Cleaned', 'An email address hard-bounced and was cleaned.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('add_subscriber', 'Add Subscriber', 'Add or update a subscriber in a Mailchimp audience.', [
                'list_id' => ['type' => 'string', 'required' => true,  'label' => 'Audience/List ID'],
                'email' => ['type' => 'string', 'required' => true,  'label' => 'Email address'],
                'first_name' => ['type' => 'string', 'required' => false, 'label' => 'First name'],
                'last_name' => ['type' => 'string', 'required' => false, 'label' => 'Last name'],
                'tags' => ['type' => 'array',  'required' => false, 'label' => 'Tags to apply'],
            ]),
            new ActionDefinition('update_subscriber_tags', 'Update Tags', 'Add or remove tags on a subscriber.', [
                'list_id' => ['type' => 'string', 'required' => true, 'label' => 'Audience/List ID'],
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Email address'],
                'tags' => ['type' => 'array',  'required' => true, 'label' => 'Tags array — each: {name, status: active|inactive}'],
            ]),
            new ActionDefinition('remove_subscriber', 'Remove Subscriber', 'Unsubscribe a member from a list.', [
                'list_id' => ['type' => 'string', 'required' => true, 'label' => 'Audience/List ID'],
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Email address'],
            ]),
            new ActionDefinition('trigger_automation', 'Trigger Automation', 'Add a subscriber to a classic automation queue.', [
                'workflow_id' => ['type' => 'string', 'required' => true, 'label' => 'Automation workflow ID'],
                'email_id' => ['type' => 'string', 'required' => true, 'label' => 'Automation email ID'],
                'email' => ['type' => 'string', 'required' => true, 'label' => 'Subscriber email address'],
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
     * Mailchimp does not provide a standard signature — URL verification is done via GET challenge.
     * POST events arrive without a signature; we accept them unconditionally (caller must use secret URL slug).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        return true;
    }

    /**
     * Mailchimp payloads are URL-encoded forms, pre-decoded to array by Laravel's request parsing.
     */
    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $type = $payload['type'] ?? 'unknown';

        return [
            [
                'source_type' => 'mailchimp',
                'source_id' => 'mc:'.($payload['data']['email'] ?? uniqid('mc_', true)),
                'payload' => $payload,
                'tags' => ['mailchimp', $type],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $accessToken = $integration->getCredentialSecret('access_token');
        $apiKey = $integration->getCredentialSecret('api_key');

        abort_unless($accessToken || $apiKey, 422, 'Mailchimp credentials not configured.');

        $base = $this->baseUrlFromIntegration($integration);
        $http = $accessToken
            ? Http::withToken($accessToken)->timeout(15)
            : Http::withBasicAuth('anystring', $apiKey)->timeout(15);

        return match ($action) {
            'add_subscriber' => $http
                ->put("{$base}/lists/{$params['list_id']}/members/{$this->subscriberHash($params['email'])}", array_filter([
                    'email_address' => $params['email'],
                    'status_if_new' => 'subscribed',
                    'status' => 'subscribed',
                    'merge_fields' => array_filter([
                        'FNAME' => $params['first_name'] ?? null,
                        'LNAME' => $params['last_name'] ?? null,
                    ]),
                    'tags' => $params['tags'] ?? null,
                ]))->json(),

            'update_subscriber_tags' => $http
                ->post("{$base}/lists/{$params['list_id']}/members/{$this->subscriberHash($params['email'])}/tags", [
                    'tags' => $params['tags'],
                ])->json(),

            'remove_subscriber' => $http
                ->patch("{$base}/lists/{$params['list_id']}/members/{$this->subscriberHash($params['email'])}", [
                    'status' => 'unsubscribed',
                ])->json(),

            'trigger_automation' => $http
                ->post("{$base}/automations/{$params['workflow_id']}/emails/{$params['email_id']}/queue", [
                    'email_address' => $params['email'],
                ])->json(),

            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function datacenterFromKey(string $apiKey): string
    {
        return explode('-', $apiKey)[1] ?? 'us1';
    }

    private function baseUrl(array $credentials): string
    {
        if (! empty($credentials['dc'])) {
            return 'https://'.$credentials['dc'].'.api.mailchimp.com/3.0';
        }

        $apiKey = $credentials['api_key'] ?? '';

        return 'https://'.$this->datacenterFromKey($apiKey).'.api.mailchimp.com/3.0';
    }

    private function baseUrlFromIntegration(Integration $integration): string
    {
        $dc = $integration->getCredentialSecret('dc');

        if ($dc) {
            return "https://{$dc}.api.mailchimp.com/3.0";
        }

        $apiKey = (string) $integration->getCredentialSecret('api_key');

        return 'https://'.$this->datacenterFromKey($apiKey).'.api.mailchimp.com/3.0';
    }

    private function subscriberHash(string $email): string
    {
        return md5(strtolower(trim($email)));
    }
}
