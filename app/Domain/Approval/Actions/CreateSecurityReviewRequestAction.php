<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\ContactIdentity;

class CreateSecurityReviewRequestAction
{
    /**
     * Create a security review approval request for a high-risk contact.
     * Skips creation if an open review already exists for this contact.
     */
    public function execute(ContactIdentity $contact): ?ApprovalRequest
    {
        $alreadyUnderReview = ApprovalRequest::withoutGlobalScopes()
            ->where('team_id', $contact->team_id)
            ->whereJsonContains('context->type', 'security_review')
            ->whereJsonContains('context->entity_id', $contact->id)
            ->where('status', ApprovalStatus::Pending->value)
            ->exists();

        if ($alreadyUnderReview) {
            return null;
        }

        return ApprovalRequest::withoutGlobalScopes()->create([
            'team_id' => $contact->team_id,
            'status' => ApprovalStatus::Pending,
            'context' => [
                'type' => 'security_review',
                'entity_type' => 'contact_identity',
                'entity_id' => $contact->id,
                'entity_display_name' => $contact->display_name,
                'risk_score' => $contact->risk_score,
                'triggered_rules' => $contact->risk_flags ?? [],
                'review_threshold' => config('security.risk.review_threshold', 30),
            ],
            'expires_at' => now()->addDays(7),
        ]);
    }
}
