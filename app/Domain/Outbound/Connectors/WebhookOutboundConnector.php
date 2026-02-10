<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Generic HTTP POST webhook connector.
 *
 * Sends outbound data as JSON POST to a configurable URL.
 * Target should contain 'url' and optionally 'headers'.
 */
class WebhookOutboundConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "webhook|{$proposal->id}");

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

            $url = $target['url'] ?? null;
            if (!$url) {
                throw new \InvalidArgumentException('No URL in webhook target');
            }

            $headers = $target['headers'] ?? [];
            $payload = [
                'experiment_id' => $proposal->experiment_id,
                'proposal_id' => $proposal->id,
                'channel' => $proposal->channel->value,
                'content' => $content,
                'target' => array_diff_key($target, array_flip(['url', 'headers'])),
            ];

            // Sign the payload if secret is provided
            $secret = $target['secret'] ?? null;
            if ($secret) {
                $headers['X-Webhook-Signature'] = hash_hmac('sha256', json_encode($payload), $secret);
            }

            $response = Http::timeout(15)
                ->withHeaders($headers)
                ->post($url, $payload);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => $response->header('X-Request-Id', 'webhook-' . now()->timestamp),
                    'response' => $response->json() ?? ['body' => substr($response->body(), 0, 500)],
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => [
                        'status' => $response->status(),
                        'body' => substr($response->body(), 0, 500),
                    ],
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
        return $channel === 'webhook';
    }
}
