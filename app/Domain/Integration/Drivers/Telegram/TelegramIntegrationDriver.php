<?php

namespace App\Domain\Integration\Drivers\Telegram;

use App\Domain\Integration\Contracts\IntegrationDriverInterface;
use App\Domain\Integration\DTOs\ActionDefinition;
use App\Domain\Integration\DTOs\HealthResult;
use App\Domain\Integration\DTOs\TriggerDefinition;
use App\Domain\Integration\Enums\AuthType;
use App\Domain\Integration\Models\Integration;
use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API integration driver.
 *
 * Coexists with the existing Domain/Telegram implementation.
 * This driver handles integration-level credential management and messaging;
 * Domain/Telegram handles bot routing and assistant chat bindings.
 *
 * Webhook signature: Telegram sends X-Telegram-Bot-Api-Secret-Token header.
 */
class TelegramIntegrationDriver implements IntegrationDriverInterface
{
    public function key(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram';
    }

    public function description(): string
    {
        return 'Send and receive Telegram messages via Bot API. Trigger workflows from Telegram commands.';
    }

    public function authType(): AuthType
    {
        return AuthType::ApiKey;
    }

    public function credentialSchema(): array
    {
        return [
            'bot_token'      => ['type' => 'password', 'required' => true,  'label' => 'Bot Token',
                                  'hint' => 'From @BotFather → /newbot → token'],
            'webhook_secret' => ['type' => 'string',   'required' => false, 'label' => 'Webhook Secret Token',
                                  'hint' => 'Set in setWebhook call; Telegram sends it as X-Telegram-Bot-Api-Secret-Token'],
        ];
    }

    public function validateCredentials(array $credentials): bool
    {
        $token = $credentials['bot_token'] ?? null;
        if (! $token) {
            return false;
        }

        try {
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");

            return $response->successful() && ($response->json('ok') === true);
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
            $response = Http::timeout(10)->get("https://api.telegram.org/bot{$token}/getMe");
            $latency  = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful() && $response->json('ok')) {
                $botName = $response->json('result.username', 'bot');

                return HealthResult::ok($latency, "Connected as @{$botName}");
            }

            return HealthResult::fail($response->json('description') ?? 'Authentication failed');
        } catch (\Throwable $e) {
            return HealthResult::fail($e->getMessage());
        }
    }

    public function triggers(): array
    {
        return [
            new TriggerDefinition('message_received',  'Message Received',  'A user sent a message to the bot.'),
            new TriggerDefinition('command_received',   'Command Received',  'A user sent a /command to the bot.'),
            new TriggerDefinition('callback_query',     'Button Clicked',    'A user clicked an inline keyboard button.'),
        ];
    }

    public function actions(): array
    {
        return [
            new ActionDefinition('send_message', 'Send Message', 'Send a message to a Telegram chat.', [
                'chat_id'    => ['type' => 'string', 'required' => true,  'label' => 'Chat ID or @username'],
                'text'       => ['type' => 'string', 'required' => true,  'label' => 'Message text (Markdown supported)'],
                'parse_mode' => ['type' => 'string', 'required' => false, 'label' => 'Parse mode: Markdown or HTML'],
            ]),
            new ActionDefinition('send_photo', 'Send Photo', 'Send a photo to a Telegram chat.', [
                'chat_id' => ['type' => 'string', 'required' => true, 'label' => 'Chat ID'],
                'photo'   => ['type' => 'string', 'required' => true, 'label' => 'Photo URL or file_id'],
                'caption' => ['type' => 'string', 'required' => false, 'label' => 'Caption text'],
            ]),
            new ActionDefinition('answer_callback', 'Answer Callback', 'Acknowledge an inline button click.', [
                'callback_query_id' => ['type' => 'string', 'required' => true, 'label' => 'Callback query ID'],
                'text'              => ['type' => 'string', 'required' => false, 'label' => 'Toast notification text'],
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
     * Telegram sends X-Telegram-Bot-Api-Secret-Token header as a plain string match.
     */
    public function verifyWebhookSignature(string $rawBody, array $headers, string $secret): bool
    {
        $header = $headers['x-telegram-bot-api-secret-token'] ?? '';

        return hash_equals($secret, $header);
    }

    public function parseWebhookPayload(array $payload, array $headers): array
    {
        // Determine trigger type
        $tags = ['telegram'];
        if (isset($payload['callback_query'])) {
            $tags[] = 'callback_query';
        } elseif (isset($payload['message'])) {
            $text = $payload['message']['text'] ?? '';
            $tags[] = str_starts_with($text, '/') ? 'command_received' : 'message_received';
        }

        return [
            [
                'source_type' => 'telegram',
                'source_id'   => (string) ($payload['update_id'] ?? uniqid('tg_', true)),
                'payload'     => $payload,
                'tags'        => $tags,
            ],
        ];
    }

    public function execute(Integration $integration, string $action, array $params): mixed
    {
        $token = $integration->getCredentialSecret('bot_token');
        abort_unless($token, 422, 'Telegram bot token not configured.');

        return match ($action) {
            'send_message'   => $this->apiCall($token, 'sendMessage', [
                'chat_id'    => $params['chat_id'],
                'text'       => $params['text'],
                'parse_mode' => $params['parse_mode'] ?? 'Markdown',
            ]),
            'send_photo'     => $this->apiCall($token, 'sendPhoto', [
                'chat_id' => $params['chat_id'],
                'photo'   => $params['photo'],
                'caption' => $params['caption'] ?? '',
            ]),
            'answer_callback' => $this->apiCall($token, 'answerCallbackQuery', [
                'callback_query_id' => $params['callback_query_id'],
                'text'              => $params['text'] ?? '',
            ]),
            default => throw new \InvalidArgumentException("Unknown action: {$action}"),
        };
    }

    private function apiCall(string $token, string $method, array $data): array
    {
        return Http::timeout(15)
            ->post("https://api.telegram.org/bot{$token}/{$method}", $data)
            ->json();
    }
}
