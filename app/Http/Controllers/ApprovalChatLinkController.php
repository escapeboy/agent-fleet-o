<?php

namespace App\Http\Controllers;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Services\ApprovalLinkSigner;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Confirmation page behind the signed Approve / Reject links posted to a
 * team's Slack / Telegram channel. The signature only proves the link came
 * from us; the decision itself needs a logged-in, non-viewer team member.
 */
class ApprovalChatLinkController extends Controller
{
    public function show(Request $request, string $approval, string $decision): View
    {
        $approvalRequest = $this->authorizeLink($request, $approval, $decision);

        return view('approvals.chat-link', [
            'approval' => $approvalRequest,
            'decision' => $decision,
            'outcome' => null,
            'alreadyDecided' => ! $this->isPending($approvalRequest),
        ]);
    }

    public function decide(Request $request, string $approval, string $decision): View
    {
        $approvalRequest = $this->authorizeLink($request, $approval, $decision);

        $render = fn (?string $outcome, bool $alreadyDecided) => view('approvals.chat-link', [
            'approval' => $approvalRequest,
            'decision' => $decision,
            'outcome' => $outcome,
            'alreadyDecided' => $alreadyDecided,
        ]);

        if (! $this->isPending($approvalRequest)) {
            return $render(null, true);
        }

        /** @var string $userId */
        $userId = $request->user()?->getAuthIdentifier();

        try {
            if ($decision === 'approve') {
                app(ApproveAction::class)->execute($approvalRequest, $userId, 'Approved from chat link');
            } else {
                app(RejectAction::class)->execute($approvalRequest, $userId, 'Rejected from chat link');
            }
        } catch (InvalidArgumentException) {
            // A concurrent decision got there first.
            return $render(null, true);
        }

        $approvalRequest->refresh();
        $status = $approvalRequest->getAttribute('status');

        return $render($status instanceof ApprovalStatus ? $status->value : (string) $status, false);
    }

    private function isPending(ApprovalRequest $approval): bool
    {
        return $approval->getAttribute('status') === ApprovalStatus::Pending;
    }

    private function authorizeLink(Request $request, string $approvalId, string $decision): ApprovalRequest
    {
        abort_unless(in_array($decision, ApprovalLinkSigner::DECISIONS, true), 404);

        $approval = ApprovalRequest::withoutGlobalScopes()->findOrFail($approvalId);
        $teamId = $approval->getAttribute('team_id');
        abort_unless(is_string($teamId) && $teamId !== '', 404);

        $team = Team::withoutGlobalScopes()->findOrFail($teamId);

        $user = $request->user();
        abort_unless($user instanceof User, 403);
        abort_unless($user->belongsToTeam($team), 403);
        // Checked directly: the generic edit-content gate is always true.
        abort_unless($user->teamRole($team)?->canEdit() === true, 403);

        // Narrow tenant scoping to the approval's (membership-checked) team so
        // the decision actions find the row even when the user's UI-active
        // team is a different one.
        app()->instance('currentTeam', $team);

        return $approval;
    }
}
