<?php

namespace App\Domain\Broadcast\Jobs;

use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Broadcast\Services\BroadcastMailer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one chunk of a broadcast's recipients. Dispatched as a batched job
 * by SendBroadcastJob; the broadcast is only rolled to a terminal status after
 * the whole batch settles, never mid-flight.
 *
 * @param  list<string>  $recipientIds  the recipient rows this chunk owns
 */
class SendBroadcastChunkJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    /**
     * @param  list<string>  $recipientIds
     */
    public function __construct(
        public string $broadcastId,
        public array $recipientIds,
    ) {}

    public function handle(BroadcastMailer $mailer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $broadcast = Broadcast::withoutGlobalScopes()->find($this->broadcastId);

        if (! $broadcast) {
            return;
        }

        $recipients = BroadcastRecipient::withoutGlobalScopes()
            ->where('team_id', $broadcast->team_id)
            ->where('broadcast_id', $broadcast->id)
            ->whereIn('id', $this->recipientIds)
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
    }
}
