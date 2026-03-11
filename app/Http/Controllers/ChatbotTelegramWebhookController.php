<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Jobs\ProcessChatbotTelegramMessageJob;
use App\Domain\Chatbot\Models\ChatbotToken;
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
