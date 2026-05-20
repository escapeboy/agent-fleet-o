<?php

namespace App\Domain\Broadcast\Actions;

use App\Domain\Broadcast\Enums\BroadcastStatus;
use App\Domain\Broadcast\Models\Broadcast;

class CancelBroadcast
{
    /**
     * @throws \RuntimeException when the broadcast is already finalized
     */
    public function execute(Broadcast $broadcast): Broadcast
    {
        if (in_array($broadcast->status, [BroadcastStatus::Sent, BroadcastStatus::Cancelled], true)) {
            throw new \RuntimeException('Broadcast is already finalized and cannot be cancelled.');
        }

        $broadcast->update(['status' => BroadcastStatus::Cancelled]);

        return $broadcast;
    }
}
