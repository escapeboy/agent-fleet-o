<?php

namespace App\Domain\Broadcast\Actions;

use App\Domain\Audience\Enums\AudienceMemberStatus;
use App\Domain\Audience\Models\AudienceMember;
use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Jobs\SendBroadcastJob;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use Illuminate\Support\Facades\DB;

class ApproveBroadcast
{
    /**
     * Approve a pending broadcast: materialize a recipient row for every
     * currently-subscribed audience member with an email, then dispatch the
     * send job. Unsubscribed members are excluded.
     *
     * @throws \RuntimeException when the broadcast is not pending approval
     */
    public function execute(Broadcast $broadcast, string $approverId): Broadcast
    {
        if ($broadcast->status !== BroadcastStatus::PendingApproval) {
            throw new \RuntimeException('Only broadcasts pending approval can be approved.');
        }

        DB::transaction(function () use ($broadcast, $approverId) {
            $members = AudienceMember::withoutGlobalScopes()
                ->where('audience_id', $broadcast->audience_id)
                ->where('status', AudienceMemberStatus::Subscribed->value)
                ->with('contactIdentity')
                ->get();

            foreach ($members as $member) {
                $email = $member->contactIdentity?->email;
                if (! $email) {
                    continue;
                }

                BroadcastRecipient::withoutGlobalScopes()->updateOrCreate(
                    [
                        'broadcast_id' => $broadcast->id,
                        'contact_identity_id' => $member->contact_identity_id,
                    ],
                    [
                        'team_id' => $broadcast->team_id,
                        'email' => $email,
                        'status' => BroadcastRecipientStatus::Pending,
                    ],
                );
            }

            $broadcast->update([
                'status' => BroadcastStatus::Sending,
                'approved_by' => $approverId,
                'approved_at' => now(),
                'recipient_count' => $broadcast->recipients()->count(),
            ]);
        });

        SendBroadcastJob::dispatch($broadcast->id)->onQueue('outbound');

        return $broadcast->refresh();
    }
}
