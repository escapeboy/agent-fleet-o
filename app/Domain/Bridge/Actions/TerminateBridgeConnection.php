<?php

namespace App\Domain\Bridge\Actions;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;

class TerminateBridgeConnection
{
    public function execute(BridgeConnection $connection): void
    {
        $connection->update([
            'status' => BridgeConnectionStatus::Disconnected,
            'disconnected_at' => now(),
        ]);
    }
}
