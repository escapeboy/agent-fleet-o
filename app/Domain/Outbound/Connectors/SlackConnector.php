<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Slack Incoming Webhook connector.
 *
 * Sends messages via Slack incoming webhook URL.
 * Target should contain 'webhook_url' or uses SLACK_WEBHOOK_URL from .env.
 */
class SlackConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "slack|{$proposal->id}");

        $existing = OutboundAction::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            return $existing;
        }

        $action = OutboundAction::create([
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sending,
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
        ]);

        try {
            $target = $proposal->target;
            $content = $proposal->content;

            $webhookUrl = $target['webhook_url'] ?? config('services.slack.webhook_url');
            if (!$webhookUrl) {
                throw new \RuntimeException('Slack webhook URL not configured');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';
            $channel = $target['channel'] ?? null;

            $payload = ['text' => $text];
            if ($channel) {
                $payload['channel'] = $channel;
            }
            if ($content['username'] ?? null) {
                $payload['username'] = $content['username'];
            }

            $response = Http::timeout(15)->post($webhookUrl, $payload);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => 'slack-' . now()->timestamp,
                    'sent_at' => now(),
                ]);
            } else {
                $action->update([
                    'status' => OutboundActionStatus::Failed,
                    'response' => ['error' => $response->body(), 'status' => $response->status()],
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
        return $channel === 'slack';
    }
}
