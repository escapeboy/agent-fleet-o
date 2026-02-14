<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * WhatsApp Cloud API connector.
 *
 * Sends messages via the Meta WhatsApp Cloud API.
 * Requires WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_ACCESS_TOKEN in .env.
 */
class WhatsAppConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "whatsapp|{$proposal->id}");

        $existing = OutboundAction::withoutGlobalScopes()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::withoutGlobalScopes()->create([
            'team_id' => $proposal->team_id,
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $phoneNumberId = config('services.whatsapp.phone_number_id');
            $accessToken = config('services.whatsapp.access_token');

            if (! $phoneNumberId || ! $accessToken) {
                throw new \RuntimeException('WhatsApp Cloud API credentials not configured');
            }

            $target = $proposal->target;
            $content = $proposal->content;

            $recipientPhone = $target['phone'] ?? $target['to'] ?? null;
            if (! $recipientPhone) {
                throw new \InvalidArgumentException('No phone number in target');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';

            $payload = [
                'messaging_product' => 'whatsapp',
                'to' => $recipientPhone,
                'type' => 'text',
                'text' => [
                    'body' => $text,
                    'preview_url' => $content['preview_url'] ?? false,
                ],
            ];

            $response = Http::timeout(15)
                ->withToken($accessToken)
                ->post("https://graph.facebook.com/v21.0/{$phoneNumberId}/messages", $payload);

            if ($response->successful()) {
                $messageId = $response->json('messages.0.id');

                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $messageId,
                    'response' => $response->json(),
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => $response->json() ?? ['error' => $response->body()],
                    'retry_count' => $action->retry_count + 1,
                ]);
            }
        } catch (\Throwable $e) {
            $action->update([
                'status' => OutboundActionStatus::Failed,
                'response' => ['error' => $e->getMessage()],
                'retry_count' => $action->retry_count + 1,
            ]);
        }

        return $action;
    }

    public function supports(string $channel): bool
    {
        return $channel === 'whatsapp';
    }
}
