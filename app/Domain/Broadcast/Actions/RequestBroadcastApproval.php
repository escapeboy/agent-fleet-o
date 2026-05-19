<?php

namespace App\Domain\Broadcast\Actions;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Services\BroadcastBudgetGuard;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;

class RequestBroadcastApproval
{
    public function __construct(
        private readonly BroadcastBudgetGuard $budgetGuard,
    ) {}

    /**
     * Submit a draft broadcast for approval after a budget check.
     *
     * @throws \RuntimeException when the broadcast is not a draft
     * @throws InsufficientBudgetException when the budget guard fails
     */
    public function execute(Broadcast $broadcast, string $requestedBy): Broadcast
    {
        if ($broadcast->status !== BroadcastStatus::Draft) {
            throw new \RuntimeException('Only draft broadcasts can be submitted for approval.');
        }

        $recipientCount = AudienceMember::withoutGlobalScopes()
            ->where('audience_id', $broadcast->audience_id)
            ->where('status', AudienceMemberStatus::Subscribed->value)
            ->count();

        $this->budgetGuard->assertCanSend($broadcast->team_id, $recipientCount);

        $broadcast->update([
            'status' => BroadcastStatus::PendingApproval,
            'requested_by' => $requestedBy,
            'recipient_count' => $recipientCount,
        ]);

        return $broadcast->refresh();
    }
}
