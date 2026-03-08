<?php

namespace App\Domain\Integration\Drivers\WhatsApp;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Business Cloud API integration driver.
 *
 * Supports sending messages, receiving inbound messages via webhook,
 * and webhook verification via X-Hub-Signature-256 (HMAC-SHA256).
 *
 * Note: The IntegrationWebhookController must handle GET challenge requests
 * for WhatsApp (hub.mode=subscribe, hub.challenge) — see parseWebhookPayload().
 */
class WhatsAppIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://graph.facebook.com/v18.0';

    public function key(): string
    {
        return 'whatsapp';
    }

    public function label(): string
    {
        return 'WhatsApp Business';
    }

    public function description(): string
    {
        return 'Send and receive WhatsApp Business messages via the Meta Cloud API.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'phone_number_id' => ['type' => 'string',   'required' => true,  'label' => 'Phone Number ID',
                'hint' => 'From Meta Developer Console → WhatsApp → Getting Started'],
            'access_token' => ['type' => 'password', 'required' => true,  'label' => 'Access Token',
                'hint' => 'Permanent System User access token from Meta Business'],
            'verify_token' => ['type' => 'string',   'required' => false, 'label' => 'Webhook Verify Token',
                'hint' => 'Set the same value in your Meta webhook configuration'],
            'webhook_secret' => ['type' => 'password', 'required' => false, 'label' => 'App Secret',
                'hint' => 'For X-Hub-Signature-256 verification (Meta App Secret)'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $phoneNumberId = $credentials['phone_number_id'] ?? null;
        $accessToken = $credentials['access_token'] ?? null;

        if (! $phoneNumberId || ! $accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get(self::API_BASE."/{$phoneNumberId}");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $phoneNumberId = $integration->config['phone_number_id']
            ?? $integration->getCredentialSecret('phone_number_id');
        $accessToken = $integration->getCredentialSecret('access_token');

        if (! $phoneNumberId || ! $accessToken) {
            return HealthResult::fail('Phone Number ID or access token not configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get(self::API_BASE."/{$phoneNumberId}");
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $displayPhone = $response->json('display_phone_number', $phoneNumberId);

                return HealthResult::ok($latency, "Connected: {$displayPhone}");
            }

            return HealthResult::fail($response->json('error.message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('message_received', 'Message Received', 'A customer sent a WhatsApp message.'),
            new TriggerDefinition('status_update', 'Status Update', 'A message delivery status changed (sent/delivered/read).'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_message', 'Send Message', 'Send a text message to a WhatsApp number.', [
                'to' => ['type' => 'string', 'required' => true, 'label' => 'Recipient phone number (E.164 format)'],
                'message' => ['type' => 'string', 'required' => true, 'label' => 'Message text'],
            ]),
            new ActionDefinition('send_template', 'Send Template', 'Send a pre-approved template message.', [
                'to' => ['type' => 'string', 'required' => true, 'label' => 'Recipient phone number'],
                'template_name' => ['type' => 'string', 'required' => true, 'label' => 'Template name'],
                'language_code' => ['type' => 'string', 'required' => true, 'label' => 'Language code (e.g. en_US)'],
                'components' => ['type' => 'array',  'required' => false, 'label' => 'Template components (variables)'],
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
     * WhatsApp uses X-Hub-Signature-256: sha256=HMAC(appSecret, rawBody)
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['x-hub-signature-256'] ?? '';

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $signals = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                $statuses = $value['statuses'] ?? [];

                foreach ($messages as $msg) {
                    $signals[] = [
                        'source_type' => 'whatsapp',
                        'source_id' => $msg['id'] ?? uniqid('wa_', true),
                        'payload' => $msg + ['contacts' => $value['contacts'] ?? []],
                        'tags' => ['whatsapp', 'message_received'],
                    ];
                }

                foreach ($statuses as $status) {
                    $signals[] = [
                        'source_type' => 'whatsapp',
                        'source_id' => $status['id'] ?? uniqid('wa_s_', true),
                        'payload' => $status,
                        'tags' => ['whatsapp', 'status_update', $status['status'] ?? 'unknown'],
                    ];
                }
            }
        }

        return $signals;
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $phoneNumberId = $integration->config['phone_number_id']
            ?? $integration->getCredentialSecret('phone_number_id');
        $accessToken = $integration->getCredentialSecret('access_token');

        abort_unless($phoneNumberId && $accessToken, 422, 'WhatsApp credentials not configured.');

        return match ($action) {
            'send_message' => $this->sendTextMessage($accessToken, $phoneNumberId, $params['to'], $params['message']),
            'send_template' => $this->sendTemplateMessage($accessToken, $phoneNumberId, $params),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function sendTextMessage(string $token, string $phoneNumberId, string $to, string $message): array
    {
        return Http::withToken($token)
            ->post(self::API_BASE."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message],
            ])
            ->json();
    }

    private function sendTemplateMessage(string $token, string $phoneNumberId, array $params): array
    {
        return Http::withToken($token)
            ->post(self::API_BASE."/{$phoneNumberId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => $params['to'],
                'type' => 'template',
                'template' => [
                    'name' => $params['template_name'],
                    'language' => ['code' => $params['language_code']],
                    'components' => $params['components'] ?? [],
                ],
            ])
            ->json();
    }
}
