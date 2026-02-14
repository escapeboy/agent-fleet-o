<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use Illuminate\Support\Facades\Http;

/**
 * Microsoft Teams connector via Power Automate Workflows webhooks.
 *
 * Sends Adaptive Card payloads to Teams channels.
 * Target should contain 'webhook_url' or uses TEAMS_WEBHOOK_URL from .env.
 */
class TeamsConnector implements OutboundConnectorInterface
{
    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "teams|{$proposal->id}");

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

            $webhookUrl = $target['webhook_url'] ?? config('services.teams.webhook_url');
            if (! $webhookUrl) {
                throw new \RuntimeException('Teams webhook URL not configured');
            }

            $text = $content['body'] ?? $content['text'] ?? 'No content generated.';
            $title = $content['title'] ?? $content['subject'] ?? 'Agent Fleet';

            // Build Adaptive Card payload for Power Automate Workflows
            $payload = [
                'type' => 'message',
                'attachments' => [
                    [
                        'contentType' => 'application/vnd.microsoft.card.adaptive',
                        'contentUrl' => null,
                        'content' => [
                            '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                            'type' => 'AdaptiveCard',
                            'version' => '1.4',
                            'body' => [
                                [
                                    'type' => 'TextBlock',
                                    'text' => $title,
                                    'weight' => 'Bolder',
                                    'size' => 'Medium',
                                ],
                                [
                                    'type' => 'TextBlock',
                                    'text' => $text,
                                    'wrap' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = Http::timeout(15)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($webhookUrl, $payload);

            if ($response->successful()) {
                $action->update([
                    'status' => OutboundActionStatus::Sent,
                    'external_id' => 'teams-'.now()->timestamp,
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
        return $channel === 'teams';
    }
}
