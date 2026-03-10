<?php

namespace App\Domain\Bridge\Actions;

use App\Domain\Bridge\Models\BridgeConnection;

class UpdateBridgeEndpoints
{
    public function execute(BridgeConnection $connection, array $endpoints): void
    {
        $connection->update([
            'endpoints' => $endpoints,
            'last_seen_at' => now(),
        ]);
    }
}
