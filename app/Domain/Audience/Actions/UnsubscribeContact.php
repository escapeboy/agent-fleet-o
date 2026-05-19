<?php

namespace App\Domain\Audience\Actions;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Shared\Models\ContactIdentity;

class UnsubscribeContact
{
    /**
     * Unsubscribe a contact from one audience, or from every audience when
     * no audience id is given (e.g. a hard bounce or spam complaint).
     *
     * @return int Number of memberships transitioned to unsubscribed
     */
    public function execute(
        string $teamId,
        ContactIdentity $contact,
        ?string $audienceId = null,
        ?string $reason = null,
    ): int {
        $members = AudienceMember::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('contact_identity_id', $contact->id)
            ->where('status', '!=', AudienceMemberStatus::Unsubscribed->value)
            ->when($audienceId, fn ($q) => $q->where('audience_id', $audienceId))
            ->get();

        foreach ($members as $member) {
            $member->update([
                'status' => AudienceMemberStatus::Unsubscribed,
                'unsubscribed_at' => now(),
                'unsubscribe_reason' => $reason,
            ]);
        }

        return $members->count();
    }
}
