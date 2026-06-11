<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Contracts\ChatbotFeedbackRecorderInterface;
use App\Domain\Chatbot\Jobs\ProcessChatbotTelegramMessageJob;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Telegram\Actions\SendTelegramReplyAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class ChatbotTelegramWebhookController extends Controller
{
    /**
     * Handle an inbound Telegram webhook push for a chatbot channel.
     *
     * The URL is chatbot-specific: POST /api/chatbot/telegram/{tokenPrefix}
     * This isolates each chatbot's Telegram bot from others (no cross-bot routing).
     * Returns 200 immediately; processing is async via ProcessChatbotTelegramMessageJob.
     */
    public function handle(Request $request, string $tokenPrefix): Response
    {
        // Resolve chatbot from token prefix (first 8 chars of API token)
        $token = ChatbotToken::where('token_prefix', $tokenPrefix)
            ->whereNull('revoked_at')
            ->first();

        if (! $token) {
            return response('', 200); // Return 200 to silence Telegram retries
        }

        $chatbot = $token->chatbot;

        if (! $chatbot || ! $chatbot->status->isActive()) {
            return response('', 200);
        }

        // Find the active Telegram channel config for this chatbot
        $channel = $chatbot->channels()
            ->where('channel_type', 'telegram')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return response('', 200);
        }

        // Verify webhook secret token if configured
        $webhookSecret = $channel->config['webhook_secret'] ?? null;
        if ($webhookSecret) {
            $incomingSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
            if ($incomingSecret !== $webhookSecret) {
                return response('', 200); // Silent fail
            }
        }

        $update = $request->json()->all();

        $callbackQuery = $update['callback_query'] ?? null;
        $callbackData = (string) ($callbackQuery['data'] ?? '');
        $botToken = $channel->config['bot_token'] ?? null;

        // A locked vote button carries `fb:noop`. The keyboard is already gone
        // for the user who voted, but a stray/forged tap can still arrive —
        // just acknowledge it so Telegram stops retrying, and do nothing else.
        if ($callbackQuery !== null && $callbackData === 'fb:noop') {
            if ($botToken && isset($callbackQuery['id'])) {
                app(SendTelegramReplyAction::class)->answerCallbackQuery(
                    $botToken,
                    (string) $callbackQuery['id'],
                );
            }

            return response('', 200);
        }

        // Per-answer vote callback (👍/👎). Record the vote and acknowledge —
        // do NOT route it through the message job as if it were a new question.
        if ($callbackQuery !== null && preg_match('/^fb:(up|down):(.+)$/', $callbackData, $m) === 1) {
            $vote = $m[1] === 'up' ? 'thumbs_up' : 'thumbs_down';
            $messageId = $m[2];

            // Authorization (anti-IDOR): this webhook is public, so a forged
            // callback could carry another tenant's message id. Reject any id
            // that belongs to a DIFFERENT chatbot's message; own-chatbot ids and
            // non-local ids (downstream/Barsy-owned, never a ChatbotMessage row)
            // pass through so the recorder seam stays substitutable.
            $belongsToOtherChatbot = ChatbotMessage::withoutGlobalScopes()
                ->where('id', $messageId)
                ->where('chatbot_id', '!=', $chatbot->id)
                ->exists();

            if (! $belongsToOtherChatbot) {
                app(ChatbotFeedbackRecorderInterface::class)->record($messageId, $vote);
            }

            $reply = app(SendTelegramReplyAction::class);

            // Lock the choice: replace the two-button keyboard on the original
            // bot message with a single, non-actionable confirmation button so
            // the vote can no longer be changed or re-cast. `fb:noop` callback
            // data means a stray tap does nothing.
            $voteMessageId = $callbackQuery['message']['message_id'] ?? null;
            $voteChatId = $callbackQuery['message']['chat']['id'] ?? null;
            if ($botToken && $voteMessageId !== null && $voteChatId !== null) {
                $lockedLabel = $vote === 'thumbs_up' ? '✅ Благодаря!' : '✅ Отбелязано';
                try {
                    $reply->editMessageReplyMarkup(
                        $botToken,
                        (string) $voteChatId,
                        (int) $voteMessageId,
                        [[['text' => $lockedLabel, 'callback_data' => 'fb:noop']]],
                    );
                } catch (\Throwable $e) {
                    // Message may be too old to edit, or already edited — the vote
                    // is recorded regardless, so never fail the webhook over this.
                    Log::warning('Failed to lock Telegram vote keyboard', [
                        'chatbot_id' => $chatbot->id,
                        'message_id' => $voteMessageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($botToken && isset($callbackQuery['id'])) {
                $reply->answerCallbackQuery(
                    $botToken,
                    (string) $callbackQuery['id'],
                    'Благодаря за обратната връзка!',
                );
            }

            return response('', 200);
        }

        $message = $update['message'] ?? $update['callback_query']['message'] ?? null;

        if (! $message) {
            return response('', 200);
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        $text = $message['text'] ?? $update['callback_query']['data'] ?? '';
        $username = ($update['callback_query']['from'] ?? $message['from'] ?? [])['username'] ?? null;

        if ($chatId !== '' && $text !== '') {
            ProcessChatbotTelegramMessageJob::dispatch($chatbot->id, $channel->id, $chatId, $text, $username);
        }

        return response('', 200);
    }
}
