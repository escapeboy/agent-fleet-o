<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Jobs\ProcessChatbotWebhookMessageJob;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChatbotWebhookController extends Controller
{
    /**
     * Handle a generic inbound webhook payload for a chatbot channel.
     *
     * POST /api/chatbot/webhook/{tokenPrefix}
     * The channel config defines a `secret` for HMAC-SHA256 validation and
     * a `payload_mapping` to extract the user message from the payload.
     */
    public function handle(Request $request, string $tokenPrefix): Response
    {
        $token = ChatbotToken::where('token_prefix', $tokenPrefix)
            ->whereNull('revoked_at')
            ->first();

        if (! $token) {
            return response('', 200);
        }

        $chatbot = $token->chatbot;

        if (! $chatbot || ! $chatbot->status->isActive()) {
            return response('', 200);
        }

        $channel = $chatbot->channels()
            ->where('channel_type', 'webhook')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return response('', 200);
        }

        // Validate HMAC-SHA256 signature if a secret is configured
        $secret = $channel->config['secret'] ?? null;
        if ($secret) {
            $signature = $request->header('X-Webhook-Signature');
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

            if (! hash_equals($expected, (string) $signature)) {
                return response('', 200); // Silent fail — don't expose validation errors
            }
        }

        // Extract user message from payload using dot-notation mapping
        $payload = $request->json()->all();
        $messageField = $channel->config['payload_mapping']['message'] ?? 'message';
        $userField = $channel->config['payload_mapping']['user_id'] ?? 'user_id';

        $text = data_get($payload, $messageField, '');
        $externalUserId = (string) data_get($payload, $userField, 'webhook');

        if ($text === '' || ! is_string($text)) {
            return response('', 200);
        }

        ProcessChatbotWebhookMessageJob::dispatch(
            chatbotId: $chatbot->id,
            channelId: $channel->id,
            externalUserId: $externalUserId,
            text: $text,
            payload: $payload,
        );

        return response('', 202);
    }
}
