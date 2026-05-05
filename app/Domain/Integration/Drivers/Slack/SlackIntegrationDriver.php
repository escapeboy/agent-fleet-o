<?php

namespace App\Domain\Integration\Drivers\Slack;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

class SlackIntegrationDriver implements IntegrationDriverInterface
{
    private const API_BASE = 'https://slack.com/api';

    public function key(): string
    {
        return 'slack';
    }

    public function label(): string
    {
        return 'Slack';
    }

    public function description(): string
    {
        return 'Receive Slack messages and events, send messages to channels and DMs.';
    }

    public function authType(): AuthType
    {
        return AuthType::OAuth2;
    }

    public function credentialSchema(): array
    {
        return [
            'access_token' => ['type' => 'string', 'required' => true, 'label' => 'Bot Access Token'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;

        if (! $token) {
            return false;
        }

        try {
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/auth.test');

            return $response->successful() && ($response->json('ok') === true);
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
            $response = Http::withToken($token)->timeout(10)->get(self::API_BASE.'/auth.test');
            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful() && $response->json('ok')) {
                $user = $response->json('user');
                $team = $response->json('team');

                return HealthResult::ok(
                    latencyMs: $latency,
                    message: $user && $team ? "Connected as {$user} on {$team}" : null,
                    identity: $user || $team ? [
                        'label' => $user && $team ? "{$user} · {$team}" : ($user ?: $team),
                        'identifier' => $response->json('team_id'),
                        'url' => $response->json('url'),
                        'metadata' => array_filter([
                            'user' => $user,
                            'user_id' => $response->json('user_id'),
                            'team' => $team,
                            'team_id' => $response->json('team_id'),
                            'bot_id' => $response->json('bot_id'),
                            'enterprise_id' => $response->json('enterprise_id'),
                        ]),
                    ] : null,
                );
            }

            return HealthResult::fail($response->json('error') ?? 'Unknown error');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('message', 'Message', 'A message was posted in a channel.'),
            new TriggerDefinition('reaction', 'Reaction Added', 'A reaction was added to a message.'),
            new TriggerDefinition('mention', 'Bot Mentioned', 'The bot was mentioned in a channel.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_message', 'Send Message', 'Post a message to a channel.', [
                'channel' => ['type' => 'string', 'required' => true],
                'text' => ['type' => 'string', 'required' => true],
            ]),
            new ActionDefinition('send_dm', 'Send DM', 'Send a direct message to a user.', [
                'user_id' => ['type' => 'string', 'required' => true],
                'text' => ['type' => 'string', 'required' => true],
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

    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $timestamp = $headers['x-slack-request-timestamp'] ?? '';
        $receivedSig = $headers['x-slack-signature'] ?? '';

        if (! $timestamp || ! $receivedSig) {
            return false;
        }

        // Reject stale requests (>5 minutes)
        $tolerance = (int) config('integrations.webhook.timestamp_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:{$rawBody}", $secret);

        return hash_equals($expected, $receivedSig);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        $type = $payload['type'] ?? 'unknown';
        $eventTs = $payload['event']['event_ts'] ?? $payload['event_id'] ?? null;

        return [
            [
                'source_type' => 'slack',
                'source_id' => $eventTs ?? uniqid('sl_', true),
                'payload' => $payload,
                'tags' => ['slack', $type],
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('access_token');

        return match ($action) {
            'send_message' => $this->sendMessage($token, $params['channel'], $params['text']),
            'send_dm' => $this->openConversationAndSend($token, $params['user_id'], $params['text']),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function sendMessage(?string $token, string $channel, string $text): array
    {
        $response = Http::withToken((string) $token)
            ->post(self::API_BASE.'/chat.postMessage', compact('channel', 'text'));

        return $response->json();
    }

    private function openConversationAndSend(?string $token, string $userId, string $text): array
    {
        $conv = Http::withToken((string) $token)
            ->post(self::API_BASE.'/conversations.open', ['users' => $userId]);

        $channel = $conv->json('channel.id');

        return $this->sendMessage($token, (string) $channel, $text);
    }
}
