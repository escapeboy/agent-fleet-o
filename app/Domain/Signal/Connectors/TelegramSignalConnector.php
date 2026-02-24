<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Polls a Telegram bot for new messages using getUpdates long-polling.
 * Uses update_id watermarking to prevent duplicate processing (same pattern as ApiPollingConnector).
 *
 * Config keys:
 *   bot_token  — encrypted Telegram Bot API token
 *   offset     — last processed update_id + 1 (watermark)
 *   team_id    — team that owns this connector
 */
class TelegramSignalConnector implements InputConnectorInterface
{
    private const TELEGRAM_API = 'https://api.telegram.org';

    private const MAX_UPDATES_PER_POLL = 100;

    private int $highestUpdateId = 0;

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $botToken = $config['bot_token'] ?? null;
        $offset = (int) ($config['offset'] ?? 0);
        $teamId = $config['team_id'] ?? null;

        if (! $botToken) {
            Log::warning('TelegramSignalConnector: bot_token not configured');

            return [];
        }

        try {
            $response = Http::timeout(10)->get(
                self::TELEGRAM_API."/bot{$botToken}/getUpdates",
                [
                    'offset' => $offset > 0 ? $offset : null,
                    'limit' => self::MAX_UPDATES_PER_POLL,
                    'timeout' => 0,
                    'allowed_updates' => ['message', 'callback_query'],
                ]
            );

            if (! $response->successful()) {
                $error = $response->json('description', 'Unknown error');
                Log::warning('TelegramSignalConnector: getUpdates failed', [
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                return [];
            }

            $updates = $response->json('result', []);
            $signals = [];

            foreach ($updates as $update) {
                $updateId = $update['update_id'] ?? 0;

                if ($updateId > $this->highestUpdateId) {
                    $this->highestUpdateId = $updateId;
                }

                $signal = $this->processUpdate($update, $teamId);
                if ($signal) {
                    $signals[] = $signal;
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('TelegramSignalConnector: Error polling updates', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'telegram';
    }

    /**
     * Return updated config with new offset (highest_update_id + 1).
     */
    public function getUpdatedConfig(array $config, array $signals): array
    {
        if ($this->highestUpdateId > 0) {
            $config['offset'] = $this->highestUpdateId + 1;
        }

        return $config;
    }

    private function processUpdate(array $update, ?string $teamId): ?Signal
    {
        $updateId = $update['update_id'];
        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;

        if (! $message) {
            return null;
        }

        $chat = $message['chat'] ?? [];
        $from = $update['callback_query']['from'] ?? $message['from'] ?? [];
        $text = $message['text'] ?? $update['callback_query']['data'] ?? '';
        $messageId = $message['message_id'] ?? null;
        $chatId = (string) ($chat['id'] ?? '');

        if (! $chatId) {
            return null;
        }

        $payload = [
            'update_id' => $updateId,
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'chat_type' => $chat['type'] ?? 'private',
            'user_id' => (string) ($from['id'] ?? ''),
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'text' => $text,
            'metadata' => [
                'chat_title' => $chat['title'] ?? null,
                'message_type' => isset($update['callback_query']) ? 'callback_query' : 'message',
            ],
        ];

        return $this->ingestAction->execute(
            sourceType: 'telegram',
            sourceIdentifier: "telegram:{$chatId}",
            payload: $payload,
            tags: ['telegram', 'chat'],
            sourceNativeId: "telegram:{$updateId}",
        );
    }
}
