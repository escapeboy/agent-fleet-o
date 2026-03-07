<?php

namespace App\Domain\Integration\Drivers\Concerns;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Abstract base for simple webhook meta-integration drivers (Zapier, Make, etc.)
 * that act both as inbound webhook receivers and outbound event senders.
 */
abstract class WebhookTriggerDriver implements IntegrationDriverInterface
{
    /**
     * Config key holding the outbound webhook URL (e.g. 'zapier_webhook_url').
     */
    abstract protected function webhookUrlConfigKey(): string;

    public function authType(): AuthType
    {
        return AuthType::WebhookOnly;
    }

    public function credentialSchema(): array
    {
        $urlKey = $this->webhookUrlConfigKey();

        return [
            $urlKey          => ['type' => 'url',      'required' => false, 'label' => 'Outbound Webhook URL',
                                  'hint' => 'Paste the webhook URL from your automation platform to send events TO it.'],
            'webhook_secret' => ['type' => 'password', 'required' => false, 'label' => 'Webhook Secret (optional)',
                                  'hint' => 'If set, verified against X-Webhook-Secret header on inbound requests.'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $url = $credentials[$this->webhookUrlConfigKey()] ?? null;

        // Webhook-only drivers are valid even without a URL (inbound-only setup)
        if (! $url) {
            return true;
        }

        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }

    public function ping(Integration $integration): HealthResult
    {
        $url = $integration->getCredentialSecret($this->webhookUrlConfigKey())
            ?? $integration->config[$this->webhookUrlConfigKey()] ?? null;

        if (! $url) {
            return HealthResult::ok(0, 'Inbound-only (no outbound webhook URL configured).');
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(10)->post($url, ['source' => 'fleetq', 'type' => 'ping']);
            $latency  = (int) ((microtime(true) - $start) * 1000);

            return $response->successful()
                ? HealthResult::ok($latency)
                : HealthResult::fail("Webhook returned HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
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
     * Plain-string match on X-Webhook-Secret header (no HMAC — platform standard).
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $header = $headers['x-webhook-secret'] ?? '';

        return hash_equals($secret, $header);
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $url = $integration->getCredentialSecret($this->webhookUrlConfigKey())
            ?? $integration->config[$this->webhookUrlConfigKey()] ?? null;

        abort_unless($url, 422, 'Outbound webhook URL not configured.');

        $payload = $params['payload'] ?? $params['data'] ?? $params;

        $response = Http::timeout(15)->post($url, $payload);

        return ['status' => $response->status(), 'body' => $response->body()];
    }
}
