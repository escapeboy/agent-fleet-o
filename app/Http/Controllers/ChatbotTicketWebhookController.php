<?php

namespace App\Http\Controllers;

use App\Domain\Chatbot\Jobs\ProcessChatbotTicketMessageJob;
use App\Domain\Chatbot\Models\ChatbotChannel;
use App\Domain\Chatbot\Models\ChatbotToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChatbotTicketWebhookController extends Controller
{
    /**
     * Handle an inbound ticket system payload for a chatbot channel.
     *
     * POST /api/chatbot/ticket/{tokenPrefix}
     * The channel config defines field mappings to extract relevant ticket data.
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
            ->where('channel_type', 'ticket_system')
            ->where('is_active', true)
            ->first();

        if (! $channel) {
            return response('', 200);
        }

        $payload = $request->json()->all();
        $mapping = $channel->config['field_mapping'] ?? [];

        // Extract standard fields using configurable dot-notation paths
        $ticketId = (string) data_get($payload, $mapping['ticket_id'] ?? 'ticket.id', '');
        $subject = (string) data_get($payload, $mapping['subject'] ?? 'ticket.subject', '');
        $body = (string) data_get($payload, $mapping['body'] ?? 'ticket.body', '');
        $requester = (string) data_get($payload, $mapping['requester'] ?? 'ticket.requester.email', '');

        // Combine subject + body into a user message if both are present
        $text = $subject !== '' && $body !== ''
            ? "{$subject}\n\n{$body}"
            : ($subject ?: $body);

        if ($text === '') {
            return response('', 200);
        }

        ProcessChatbotTicketMessageJob::dispatch(
            chatbotId: $chatbot->id,
            channelId: $channel->id,
            ticketId: $ticketId,
            requester: $requester,
            text: $text,
            payload: $payload,
        );

        return response('', 202);
    }
}
