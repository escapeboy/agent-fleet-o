<?php

declare(strict_types=1);

namespace App\Domain\Outbound\Actions;

use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Signal\Models\Signal;

/**
 * Creates an outbound email reply to an IMAP-sourced signal,
 * preserving thread context via In-Reply-To and References headers.
 */
class ReplyToEmailSignalAction
{
    public function __construct(
        private readonly SendOutboundAction $send,
    ) {}

    public function execute(
        string $signalId,
        string $body,
        string $teamId,
        ?string $experimentId = null,
        bool $autoSend = false,
    ): OutboundProposal {
        /** @var Signal $signal */
        $signal = Signal::withoutGlobalScopes()
            ->where('id', $signalId)
            ->where('team_id', $teamId)
            ->firstOrFail();

        if ($signal->source_type !== 'email') {
            throw new \InvalidArgumentException(
                "Signal {$signalId} is not email-sourced (source_type: {$signal->source_type}). Only email signals can be replied to.",
            );
        }

        $replyTo = $signal->payload['from'] ?? null;
        if (! $replyTo || ! filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(
                "Signal {$signalId} has no valid 'from' address to reply to.",
            );
        }

        $target = [
            'email' => $replyTo,
            'in_reply_to' => $signal->payload['message_id'] ?? null,
            'references' => $signal->payload['message_id'] ?? null,
            'reply_subject' => $signal->payload['subject'] ?? '',
        ];

        $proposal = OutboundProposal::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'experiment_id' => $experimentId,
            'channel' => OutboundChannel::Email,
            'target' => $target,
            'content' => ['body' => $body],
            'status' => OutboundProposalStatus::Approved,
            'risk_score' => 0.0,
        ]);

        if ($autoSend) {
            $this->send->execute($proposal);
        }

        return $proposal;
    }
}
