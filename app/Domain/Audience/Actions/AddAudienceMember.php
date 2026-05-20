<?php

namespace App\Domain\Audience\Actions;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\Audience;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Shared\Models\ContactIdentity;

class AddAudienceMember
{
    /**
     * Add a contact to an audience, or re-subscribe them if they had left.
     */
    public function execute(Audience $audience, ContactIdentity $contact): AudienceMember
    {
        return AudienceMember::withoutGlobalScopes()->updateOrCreate(
            [
                'audience_id' => $audience->id,
                'contact_identity_id' => $contact->id,
            ],
            [
                'team_id' => $audience->team_id,
                'status' => AudienceMemberStatus::Subscribed,
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
                'unsubscribe_reason' => null,
            ],
        );
    }
}
