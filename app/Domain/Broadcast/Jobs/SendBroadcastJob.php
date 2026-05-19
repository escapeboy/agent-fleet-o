<?php

namespace App\Domain\Broadcast\Jobs;

use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Broadcast\Services\BroadcastMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers an approved broadcast to every pending recipient, then rolls the
 * broadcast up to a terminal status.
 *
 * For very large audiences this should be chunked into per-recipient jobs;
 * the single-job approach is sufficient at current scale (see sprint plan).
 */
class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public string $broadcastId) {}

    public function handle(BroadcastMailer $mailer): void
    {
        $broadcast = Broadcast::withoutGlobalScopes()->find($this->broadcastId);

        if (! $broadcast || $broadcast->status !== BroadcastStatus::Sending) {
            return;
        }

        $recipients = BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipientStatus::Pending->value)
            ->get();

        foreach ($recipients as $recipient) {
            try {
                $result = $mailer->send(
                    teamId: $broadcast->team_id,
                    toEmail: $recipient->email,
                    subject: $broadcast->subject,
                    html: $broadcast->body,
                    idempotencyKey: hash('xxh128', "broadcast|{$recipient->id}"),
                );

                $recipient->update([
                    'status' => BroadcastRecipientStatus::Sent,
                    'message_id' => $result['message_id'],
                    'sent_at' => now(),
                ]);
            } catch (\Throwable $e) {
                $recipient->update([
                    'status' => BroadcastRecipientStatus::Failed,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $sentCount = BroadcastRecipient::withoutGlobalScopes()
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipientStatus::Sent->value)
            ->count();

        $broadcast->update([
            'status' => $sentCount > 0 ? BroadcastStatus::Sent : BroadcastStatus::Failed,
            'sent_at' => now(),
        ]);
    }
}
