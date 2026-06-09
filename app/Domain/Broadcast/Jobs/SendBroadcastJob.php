<?php

namespace App\Domain\Broadcast\Jobs;

use App\Domain\Broadcast\Enums\BroadcastRecipientStatus;
use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Broadcast\Models\BroadcastRecipient;
use App\Domain\Broadcast\Services\BroadcastBudgetGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Orchestrates delivery of an approved broadcast: gates on budget, fans the
 * pending recipients out across batched SendBroadcastChunkJob jobs, and rolls
 * the broadcast to a terminal status only once the whole batch settles.
 *
 * The per-recipient send loop lives in SendBroadcastChunkJob so large audiences
 * never run in a single 600s synchronous loop.
 */
class SendBroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Recipients per chunk job. */
    private const CHUNK_SIZE = 100;

    public function __construct(public string $broadcastId) {}

    public function handle(BroadcastBudgetGuard $budgetGuard): void
    {
        $broadcast = Broadcast::withoutGlobalScopes()->find($this->broadcastId);

        if (! $broadcast || $broadcast->status !== BroadcastStatus::Sending) {
            return;
        }

        $pendingQuery = BroadcastRecipient::withoutGlobalScopes()
            ->where('team_id', $broadcast->team_id)
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipientStatus::Pending->value);

        $pendingCount = (clone $pendingQuery)->count();

        if ($pendingCount === 0) {
            self::finalize($broadcast->id);

            return;
        }

        $budgetGuard->assertCanSend($broadcast->team_id, $pendingCount);

        $jobs = [];
        $pendingQuery->select('id')->chunkById(self::CHUNK_SIZE, function ($recipients) use ($broadcast, &$jobs) {
            $jobs[] = new SendBroadcastChunkJob(
                broadcastId: $broadcast->id,
                recipientIds: $recipients->pluck('id')->all(),
            );
        });

        $broadcastId = $broadcast->id;

        Bus::batch($jobs)
            ->name("broadcast:{$broadcastId}")
            ->onQueue('outbound')
            ->finally(function () use ($broadcastId) {
                self::finalize($broadcastId);
            })
            ->dispatch();
    }

    /**
     * Roll the broadcast to a terminal status once every chunk has settled.
     */
    private static function finalize(string $broadcastId): void
    {
        $broadcast = Broadcast::withoutGlobalScopes()->find($broadcastId);

        if (! $broadcast || $broadcast->status !== BroadcastStatus::Sending) {
            return;
        }

        $sentCount = BroadcastRecipient::withoutGlobalScopes()
            ->where('team_id', $broadcast->team_id)
            ->where('broadcast_id', $broadcast->id)
            ->where('status', BroadcastRecipientStatus::Sent->value)
            ->count();

        $broadcast->update([
            'status' => $sentCount > 0 ? BroadcastStatus::Sent : BroadcastStatus::Failed,
            'sent_at' => now(),
        ]);
    }
}
