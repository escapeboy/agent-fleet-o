<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Contracts\ChatbotFeedbackRecorderInterface;
use App\Domain\Chatbot\Jobs\ProcessChatbotTelegramMessageJob;
use App\Domain\Chatbot\Models\ChatbotMessage;
use App\Domain\Chatbot\Models\ChatbotToken;
use App\Domain\Telegram\Actions\SendTelegramReplyAction;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        // Per-answer vote callback (👍/👎). Record the vote and acknowledge —
        // do NOT route it through the message job as if it were a new question.
        $callbackQuery = $update['callback_query'] ?? null;
        if ($callbackQuery !== null && preg_match('/^fb:(up|down):(.+)$/', (string) ($callbackQuery['data'] ?? ''), $m) === 1) {
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

            $botToken = $channel->config['bot_token'] ?? null;
            if ($botToken && isset($callbackQuery['id'])) {
                app(SendTelegramReplyAction::class)->answerCallbackQuery(
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
