<?php

namespace App\Domain\Telegram\Actions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramReplyAction
{
    private const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Send a placeholder message and return the Telegram message_id for later editing.
     * Returns null on failure.
     */
    public function sendPlaceholder(string $botToken, string $chatId, string $text = '<i>Generating...</i>'): ?int
    {
        $response = Http::timeout(10)->post(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ],
        );

        if (! $response->successful()) {
            return null;
        }

        return $response->json('result.message_id');
    }

    /**
     * Edit a previously sent Telegram message (used for live streaming effect).
     * Silently ignores rate limit errors (Telegram allows max 20 edits/minute/chat).
     */
    public function editMessage(string $botToken, string $chatId, int $messageId, string $text): void
    {
        $text = $this->convertMarkdownToHtml($text);
        // Truncate to Telegram's limit for edits
        if (mb_strlen($text) > self::MAX_MESSAGE_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_MESSAGE_LENGTH - 3).'...';
        }

        Http::timeout(5)->post(
            "https://api.telegram.org/bot{$botToken}/editMessageText",
            [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ],
        );
        // Deliberately not checking response — rate limit 429s are expected during streaming
    }

    /**
     * Send a text reply to a Telegram chat. Long messages are split at MAX_MESSAGE_LENGTH.
     *
     * When $feedbackId is non-null, a 👍/👎 inline keyboard is attached to the
     * final chunk with callback data `fb:up:<id>` / `fb:down:<id>` so the channel
     * can collect a per-answer vote. When null, behaves exactly as before.
     */
    public function execute(string $botToken, string $chatId, string $text, string $parseMode = 'HTML', ?string $feedbackId = null): bool
    {
        // Sanitize text for Telegram HTML mode
        if ($parseMode === 'HTML') {
            $text = $this->convertMarkdownToHtml($text);
        }

        // Split long messages
        $chunks = $this->splitMessage($text);
        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $payload = [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => $parseMode,
            ];

            // Attach voting buttons only to the final chunk.
            if ($feedbackId !== null && $index === $lastIndex) {
                $payload['reply_markup'] = json_encode([
                    'inline_keyboard' => [[
                        ['text' => '👍', 'callback_data' => "fb:up:{$feedbackId}"],
                        ['text' => '👎', 'callback_data' => "fb:down:{$feedbackId}"],
                    ]],
                ]);
            }

            $response = Http::timeout(15)->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                $payload,
            );

            if (! $response->successful()) {
                Log::warning('SendTelegramReplyAction: Failed to send message', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'error' => $response->json('description'),
                ]);

                // If HTML parse fails, retry as plain text
                if ($response->status() === 400 && $parseMode !== 'MarkdownV2') {
                    Http::timeout(15)->post(
                        "https://api.telegram.org/bot{$botToken}/sendMessage",
                        [
                            'chat_id' => $chatId,
                            'text' => strip_tags($chunk),
                        ],
                    );
                }

                return false;
            }
        }

        return true;
    }

    /**
     * Acknowledge a callback_query (e.g. a vote button press) with an optional
     * toast shown to the user. Telegram requires every callback_query to be
     * answered; failures are non-fatal so they are not surfaced.
     */
    public function answerCallbackQuery(string $botToken, string $callbackQueryId, string $text = ''): void
    {
        Http::timeout(10)->post(
            "https://api.telegram.org/bot{$botToken}/answerCallbackQuery",
            [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ],
        );
    }

    /**
     * Convert common Markdown to Telegram HTML format.
     */
    private function convertMarkdownToHtml(string $text): string
    {
        // Bold: **text** → <b>text</b>
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $text) ?? $text;
        // Italic: *text* → <i>text</i>
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '<i>$1</i>', $text) ?? $text;
        // Code: `text` → <code>text</code>
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
        // Strip footnotes like [1] or [[source]]
        $text = preg_replace('/\[\[?\d+\]?\]/', '', $text) ?? $text;

        return $text;
    }

    /**
     * @return list<string>
     */
    private function splitMessage(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_MESSAGE_LENGTH) {
            return [$text];
        }

        $chunks = [];
        while (mb_strlen($text) > 0) {
            $chunk = mb_substr($text, 0, self::MAX_MESSAGE_LENGTH);
            // Try to split at a newline boundary
            $lastNewline = mb_strrpos($chunk, "\n");
            if ($lastNewline !== false && $lastNewline > self::MAX_MESSAGE_LENGTH * 0.5) {
                $chunk = mb_substr($chunk, 0, $lastNewline);
            }
            $chunks[] = $chunk;
            $text = mb_substr($text, mb_strlen($chunk));
        }

        return $chunks;
    }
}
