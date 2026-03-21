<?php

namespace App\Domain\Outbound\Connectors;

use App\Domain\Outbound\Contracts\OutboundConnectorInterface;
use App\Domain\Outbound\Enums\OutboundActionStatus;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Services\NotificationService;

/**
 * In-app notification connector.
 *
 * Sends experiment results as platform notifications to team members
 * instead of external delivery channels.
 */
class NotificationConnector implements OutboundConnectorInterface
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function send(OutboundProposal $proposal): OutboundAction
    {
        $idempotencyKey = hash('xxh128', "notification|{$proposal->id}");

        $existing = OutboundAction::withoutGlobalScopes()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        $title = $proposal->content['subject'] ?? 'Experiment Result';
        $body = $proposal->content['body'] ?? $proposal->content['summary'] ?? '';

        $this->notificationService->notifyTeam(
            teamId: $proposal->team_id,
            type: 'experiment_outbound',
            title: $title,
            body: $body,
            actionUrl: $proposal->content['action_url'] ?? null,
            data: [
                'proposal_id' => $proposal->id,
                'experiment_id' => $proposal->experiment_id,
                'channel' => 'notification',
            ],
        );

        return OutboundAction::withoutGlobalScopes()->create([
            'team_id' => $proposal->team_id,
            'outbound_proposal_id' => $proposal->id,
            'status' => OutboundActionStatus::Sent,
            'external_id' => 'notification-'.$proposal->id,
            'response' => ['channel' => 'in_app_notification', 'delivered' => true],
            'idempotency_key' => $idempotencyKey,
            'retry_count' => 0,
            'sent_at' => now(),
        ]);
    }

    public function supports(string $channel): bool
    {
        return $channel === 'notification';
    }
}
