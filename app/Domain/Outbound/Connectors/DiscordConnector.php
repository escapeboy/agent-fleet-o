<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Discord webhook connector.
 *
 * Sends messages via Discord webhook URL with optional embeds.
 * Target should contain 'webhook_url'.
 */
class DiscordConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "discord|{$proposal->id}");

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

            $webhookUrl = $target['webhook_url'] ?? null;
            if (! $webhookUrl) {
                throw new \RuntimeException('Discord webhook URL not configured in target');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';

            $payload = ['content' => $text];

            // Support optional embeds
            if (! empty($content['embeds'])) {
                $payload['embeds'] = $content['embeds'];
            }

            if ($content['username'] ?? null) {
                $payload['username'] = $content['username'];
            }

            if ($content['avatar_url'] ?? null) {
                $payload['avatar_url'] = $content['avatar_url'];
            }

            $response = Http::timeout(15)
                ->post($webhookUrl.'?wait=true', $payload);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $response->json('id'),
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
        return $channel === 'discord';
    }
}
