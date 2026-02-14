<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Google Chat webhook connector.
 *
 * Sends messages via Google Chat space webhooks.
 * Target should contain 'webhook_url' or uses GOOGLE_CHAT_WEBHOOK_URL from .env.
 */
class GoogleChatConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "google_chat|{$proposal->id}");

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
            $target = $proposal->target;
            $content = $proposal->content;

            $webhookUrl = $target['webhook_url'] ?? config('services.google_chat.webhook_url');
            if (! $webhookUrl) {
                throw new \RuntimeException('Google Chat webhook URL not configured');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';

            $payload = ['text' => $text];

            // Support optional card format
            if (! empty($content['cards'])) {
                $payload['cards'] = $content['cards'];
            }

            $response = Http::timeout(15)->post($webhookUrl, $payload);

            if ($response->successful()) {
                $threadName = $response->json('thread.name') ?? $response->json('name');

                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $threadName ?? 'gchat-'.now()->timestamp,
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
        return $channel === 'google_chat';
    }
}
