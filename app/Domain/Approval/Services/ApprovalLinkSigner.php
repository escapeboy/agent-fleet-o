<?php

namespace App\Domain\Approval\Services;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

/**
 * Builds expiring signed links for deciding an approval from a chat message.
 * The link only opens a confirmation page; the decision still requires a
 * logged-in team member.
 */
class ApprovalLinkSigner
{
    public const DECISIONS = ['approve', 'reject'];

    public function url(ApprovalRequest $approval, string $decision): string
    {
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new InvalidArgumentException("Unsupported approval decision [{$decision}].");
        }

        $expiresAt = $approval->expires_at ?? now()->addHours(48);

        return URL::temporarySignedRoute('approvals.chat-link.show', $expiresAt, [
            'approval' => $approval->id,
            'decision' => $decision,
        ]);
    }
}
