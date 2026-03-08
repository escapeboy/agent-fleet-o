<?php

namespace App\Domain\Integration\Drivers\Teams;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Teams integration via Incoming Webhook (WebhookOnly auth).
 *
 * Supports outbound messaging only (no inbound without Bot Framework).
 * Incoming Webhook URL is obtained from Teams channel settings.
 */
class TeamsIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'teams';
    }

    public function label(): string
    {
        return 'Microsoft Teams';
    }

    public function description(): string
    {
        return 'Send messages and notifications to Microsoft Teams channels via Incoming Webhooks.';
    }

    public function authType(): AuthType
    {
        return AuthType::WebhookOnly;
    }

    public function credentialSchema(): array
    {
        return [
            'webhook_url' => ['type' => 'url', 'required' => true, 'label' => 'Incoming Webhook URL',
                'hint' => 'From Teams channel → Connectors → Incoming Webhook'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $url = $credentials['webhook_url'] ?? null;
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Teams webhook URLs must start with the MS domain
        return str_contains($url, 'webhook.office.com') || str_contains($url, 'outlook.office.com');
    }

    public function ping(Integration $integration): HealthResult
    {
        $url = $integration->getCredentialSecret('webhook_url')
            ?? $integration->config['webhook_url'] ?? null;

        if (! $url) {
            return HealthResult::fail('No webhook URL configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(10)->post($url, [
                '@type' => 'MessageCard',
                '@context' => 'http://schema.org/extensions',
                'text' => 'FleetQ integration health check.',
            ]);
            $latency = (int) ((microtime(true) - $start) * 1000);

            return $response->successful()
                ? HealthResult::ok($latency)
                : HealthResult::fail("Teams returned HTTP {$response->status()}");
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        // Inbound events require Bot Framework — not implemented in this phase
        return [];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_message', 'Send Message', 'Post a message card to a Teams channel.', [
                'text' => ['type' => 'string', 'required' => true,  'label' => 'Message text'],
                'title' => ['type' => 'string', 'required' => false, 'label' => 'Card title (optional)'],
                'color' => ['type' => 'string', 'required' => false, 'label' => 'Theme colour hex (e.g. 0076D7)'],
            ]),
            new ActionDefinition('send_adaptive_card', 'Send Adaptive Card', 'Post a structured Adaptive Card to Teams.', [
                'card_body' => ['type' => 'array', 'required' => true, 'label' => 'Adaptive Card body array'],
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
        return true; // Not applicable for outbound-only incoming webhook driver
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        return [];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $url = $integration->getCredentialSecret('webhook_url')
            ?? $integration->config['webhook_url'] ?? null;

        abort_unless($url, 422, 'Teams webhook URL not configured.');

        return match ($action) {
            'send_message' => $this->sendMessageCard($url, $params['text'], $params['title'] ?? null, $params['color'] ?? null),
            'send_adaptive_card' => $this->sendAdaptiveCard($url, $params['card_body']),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function sendMessageCard(string $url, string $text, ?string $title, ?string $color): array
    {
        $payload = [
            '@type' => 'MessageCard',
            '@context' => 'http://schema.org/extensions',
            'summary' => $title ?? $text,
            'themeColor' => $color ?? '0076D7',
            'text' => $text,
        ];

        if ($title) {
            $payload['title'] = $title;
        }

        $response = Http::timeout(15)->post($url, $payload);

        return ['status' => $response->status(), 'body' => $response->body()];
    }

    private function sendAdaptiveCard(string $url, array $cardBody): array
    {
        $payload = [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    'type' => 'AdaptiveCard',
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'version' => '1.3',
                    'body' => $cardBody,
                ],
            ]],
        ];

        $response = Http::timeout(15)->post($url, $payload);

        return ['status' => $response->status(), 'body' => $response->body()];
    }
}
