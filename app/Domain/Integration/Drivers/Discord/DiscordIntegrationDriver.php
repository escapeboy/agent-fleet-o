<?php

namespace App\Domain\Integration\Drivers\Discord;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class DiscordIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://discord.com/api/v10';

    public function key(): string
    {
        return 'discord';
    }

    public function label(): string
    {
        return 'Discord';
    }

    public function description(): string
    {
        return 'Receive Discord messages and events, send messages to channels and DMs.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'bot_token' => ['type' => 'password', 'required' => true,  'label' => 'Bot Token',
                'hint' => 'From the Discord Developer Portal → Bot → Token'],
            'application_id' => ['type' => 'string',   'required' => false, 'label' => 'Application ID'],
            'public_key' => ['type' => 'string',   'required' => false, 'label' => 'Public Key',
                'hint' => 'Required for webhook signature verification'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['bot_token'] ?? null;
        if (! $token) {
            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Bot {$token}"])
                ->timeout(10)
                ->get(self::API_BASE.'/users/@me');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function ping(Integration $integration): HealthResult
    {
        $token = $integration->getCredentialSecret('bot_token');
        if (! $token) {
            return HealthResult::fail('No bot token configured.');
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders(['Authorization' => "Bot {$token}"])
                ->timeout(10)
                ->get(self::API_BASE.'/users/@me');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $username = $response->json('username', 'Bot');
                $userId = $response->json('id');
                $discriminator = $response->json('discriminator');

                return HealthResult::ok(
                    latencyMs: $latency,
                    message: "Connected as {$username}",
                    identity: [
                        'label' => $username.($discriminator && $discriminator !== '0' ? '#'.$discriminator : ''),
                        'identifier' => $userId,
                        'url' => null,
                        'metadata' => array_filter([
                            'user_id' => $userId,
                            'global_name' => $response->json('global_name'),
                        ]),
                    ],
                );
            }

            return HealthResult::fail($response->json('message') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('message_created', 'Message Received', 'A message was posted in a channel.'),
            new TriggerDefinition('reaction_added', 'Reaction Added', 'A reaction was added to a message.'),
            new TriggerDefinition('thread_created', 'Thread Created', 'A new thread was created.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_message', 'Send Message', 'Post a message to a Discord channel.', [
                'channel_id' => ['type' => 'string', 'required' => true,  'label' => 'Channel ID'],
                'content' => ['type' => 'string', 'required' => true,  'label' => 'Message text'],
                'embed' => ['type' => 'array',  'required' => false, 'label' => 'Embed object (optional)'],
            ]),
            new ActionDefinition('send_dm', 'Send DM', 'Send a direct message to a Discord user.', [
                'user_id' => ['type' => 'string', 'required' => true, 'label' => 'User ID'],
                'content' => ['type' => 'string', 'required' => true, 'label' => 'Message text'],
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
     * Discord uses Ed25519 signature verification (not HMAC).
     * Headers: X-Signature-Ed25519 + X-Signature-Timestamp
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $signature = $headers['x-signature-ed25519'] ?? '';
        $timestamp = $headers['x-signature-timestamp'] ?? '';

        if (! $signature || ! $timestamp) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                sodium_hex2bin($signature),
                $timestamp.$rawBody,
                sodium_hex2bin($secret),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $type = $payload['t'] ?? $payload['type'] ?? 'unknown';

        return [
            [
                'source_type' => 'discord',
                'source_id' => $payload['id'] ?? uniqid('dc_', true),
                'payload' => $payload,
                'tags' => ['discord', strtolower($type)],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('bot_token');

        return match ($action) {
            'send_message' => $this->sendMessage($token, $params['channel_id'], $params['content'], $params['embed'] ?? null),
            'send_dm' => $this->sendDm($token, $params['user_id'], $params['content']),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function sendMessage(?string $token, string $channelId, string $content, ?array $embed = null): array
    {
        $body = ['content' => $content];
        if ($embed) {
            $body['embeds'] = [$embed];
        }

        return Http::withHeaders(['Authorization' => "Bot {$token}"])
            ->post(self::API_BASE."/channels/{$channelId}/messages", $body)
            ->json();
    }

    private function sendDm(?string $token, string $userId, string $content): array
    {
        // Open a DM channel first
        $dm = Http::withHeaders(['Authorization' => "Bot {$token}"])
            ->post(self::API_BASE.'/users/@me/channels', ['recipient_id' => $userId])
            ->json();

        $channelId = $dm['id'] ?? null;
        if (! $channelId) {
            return ['error' => 'Could not open DM channel'];
        }

        return $this->sendMessage($token, $channelId, $content);
    }
}
