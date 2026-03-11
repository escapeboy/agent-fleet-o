<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Jobs\ProcessChatbotSlackMessageJob;
use App\Domain\Chatbot\Models\ChatbotToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotSlackWebhookController extends Controller
{
    /**
     * Handle Slack Events API payload for a chatbot channel.
     *
     * URL: POST /api/chatbot/slack/{tokenPrefix}
     * Handles: url_verification challenge + message events.
     * Signing secret is stored per-channel in config['signing_secret'].
     */
    public function handle(Request $request, string $tokenPrefix): JsonResponse
    {
        // Resolve chatbot from token prefix
        $token = ChatbotToken::where('token_prefix', $tokenPrefix)
            ->whereNull('revoked_at')
            ->first();

        if (! $token) {
            return response()->json(['ok' => true]);
        }

        $chatbot = $token->chatbot;

        if (! $chatbot || ! $chatbot->status->isActive()) {
            return response()->json(['ok' => true]);
        }

        $channel = $chatbot->channels()
            ->where('channel_type', 'slack')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return response()->json(['ok' => true]);
        }

        // Verify Slack signing secret (HMAC-SHA256)
        $signingSecret = $channel->config['signing_secret'] ?? null;
        if ($signingSecret && ! $this->verifySignature($request, $signingSecret)) {
            return response()->json(['ok' => true]);
        }

        $payload = $request->json()->all();

        // Handle Slack URL verification challenge (one-time setup)
        if (($payload['type'] ?? '') === 'url_verification') {
            return response()->json(['challenge' => $payload['challenge'] ?? '']);
        }

        // Only handle direct user messages — ignore bot messages and subtypes
        $event = $payload['event'] ?? [];
        if (($event['type'] ?? '') !== 'message'
            || isset($event['bot_id'])
            || isset($event['subtype'])
        ) {
            return response()->json(['ok' => true]);
        }

        $text = $event['text'] ?? '';
        $slackUserId = $event['user'] ?? '';
        $slackChannelId = $event['channel'] ?? '';

        if ($text !== '' && $slackUserId !== '') {
            ProcessChatbotSlackMessageJob::dispatch(
                $chatbot->id,
                $channel->id,
                $slackUserId,
                $slackChannelId,
                $text,
            );
        }

        return response()->json(['ok' => true]);
    }

    private function verifySignature(Request $request, string $signingSecret): bool
    {
        $timestamp = $request->header('X-Slack-Request-Timestamp');
        $signature = $request->header('X-Slack-Signature');

        if (! $timestamp || ! $signature) {
            return false;
        }

        // Reject requests older than 5 minutes (replay protection)
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $baseString = "v0:{$timestamp}:{$request->getContent()}";
        $computed = 'v0='.hash_hmac('sha256', $baseString, $signingSecret);

        return hash_equals($computed, $signature);
    }
}
