<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Support\Facades\DB;

class CreateSecurityReviewRequestAction
{
    /**
     * Create a security review approval request for a high-risk contact.
     * Skips creation if an open review already exists for this contact.
     * Wrapped in a transaction to prevent TOCTOU race on concurrent job retries.
     */
    public function execute(ContactIdentity $contact): ?ApprovalRequest
    {
        return DB::transaction(function () use ($contact) {
            $alreadyUnderReview = ApprovalRequest::withoutGlobalScopes()
                ->where('team_id', $contact->team_id)
                ->where('context->type', 'security_review')
                ->where('context->entity_id', $contact->id)
                ->where('status', ApprovalStatus::Pending->value)
                ->lockForUpdate()
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
        });
    }
}
