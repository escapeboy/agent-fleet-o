<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    /** Media types that carry a file_id for downloading. */
    private const MEDIA_FIELDS = ['photo', 'audio', 'voice', 'video', 'document', 'sticker', 'video_note'];

    private int $highestUpdateId = 0;

    private string $currentBotToken = '';

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

        $this->currentBotToken = $botToken;

        try {
            $response = Http::timeout(10)->get(
                self::TELEGRAM_API."/bot{$botToken}/getUpdates",
                [
                    'offset' => $offset > 0 ? $offset : null,
                    'limit' => self::MAX_UPDATES_PER_POLL,
                    'timeout' => 0,
                    'allowed_updates' => ['message', 'callback_query'],
                ],
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

        // Detect media type and file_id
        $mediaType = null;
        $fileId = null;
        $mediaMeta = [];

        foreach (self::MEDIA_FIELDS as $field) {
            if (isset($message[$field])) {
                $mediaType = $field;
                // Photos arrive as array of sizes; take the largest
                if ($field === 'photo' && is_array($message[$field])) {
                    $largest = end($message[$field]);
                    $fileId = $largest['file_id'] ?? null;
                    $mediaMeta = ['width' => $largest['width'] ?? null, 'height' => $largest['height'] ?? null];
                } else {
                    $fileId = $message[$field]['file_id'] ?? null;
                    $mediaMeta = array_filter([
                        'file_name' => $message[$field]['file_name'] ?? null,
                        'mime_type' => $message[$field]['mime_type'] ?? null,
                        'file_size' => $message[$field]['file_size'] ?? null,
                        'duration' => $message[$field]['duration'] ?? null,
                    ]);
                }
                break;
            }
        }

        $payload = [
            'update_id' => $updateId,
            'message_id' => $messageId,
            'chat_id' => $chatId,
            'chat_type' => $chat['type'] ?? 'private',
            'user_id' => (string) ($from['id'] ?? ''),
            'username' => $from['username'] ?? null,
            'first_name' => $from['first_name'] ?? null,
            'text' => $text ?: ($message['caption'] ?? ''),
            'media_type' => $mediaType,
            'metadata' => array_filter([
                'chat_title' => $chat['title'] ?? null,
                'message_type' => isset($update['callback_query']) ? 'callback_query' : 'message',
                'media' => $mediaMeta ?: null,
            ]),
        ];

        // Download media file if present
        $files = [];
        if ($fileId && $this->currentBotToken) {
            $file = $this->downloadMedia($this->currentBotToken, $fileId, $mediaMeta['file_name'] ?? null);
            if ($file) {
                $files[] = $file;
                $payload['has_media'] = true;
            }
        }

        $tags = array_filter(['telegram', 'chat', $mediaType]);

        return $this->ingestAction->execute(
            sourceType: 'telegram',
            sourceIdentifier: "telegram:{$chatId}",
            payload: $payload,
            tags: array_values($tags),
            sourceNativeId: "telegram:{$updateId}",
            files: $files,
            teamId: $teamId,
            senderHints: array_filter([
                'name' => $from['first_name'] ?? $from['username'] ?? null,
            ]),
        );
    }

    /**
     * Download a Telegram media file via getFile API and return a temporary UploadedFile.
     */
    private function downloadMedia(string $botToken, string $fileId, ?string $originalName): ?UploadedFile
    {
        try {
            // Get file path from Telegram
            $fileResponse = Http::timeout(10)->get(
                self::TELEGRAM_API."/bot{$botToken}/getFile",
                ['file_id' => $fileId],
            );

            if (! $fileResponse->successful() || ! $fileResponse->json('ok')) {
                return null;
            }

            $filePath = $fileResponse->json('result.file_path');
            if (! $filePath) {
                return null;
            }

            // Download the actual file
            $downloadUrl = self::TELEGRAM_API."/file/bot{$botToken}/{$filePath}";
            $content = Http::timeout(30)->get($downloadUrl)->body();

            if (empty($content)) {
                return null;
            }

            // Write to a temporary file
            $tempPath = tempnam(sys_get_temp_dir(), 'tg_media_');
            file_put_contents($tempPath, $content);

            $fileName = $originalName ?? basename($filePath);
            $mimeType = mime_content_type($tempPath) ?: 'application/octet-stream';

            return new UploadedFile($tempPath, $fileName, $mimeType, null, true);
        } catch (\Throwable $e) {
            Log::warning('TelegramSignalConnector: media download failed', [
                'file_id' => $fileId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}

