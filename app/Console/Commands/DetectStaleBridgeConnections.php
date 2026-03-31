<?php

namespace App\Console\Commands;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Detects bridge connections stuck in "connected" status despite no heartbeat.
 *
 * This prevents ghost connections where the bridge daemon crashed or lost
 * network, but the server-side record was never updated to "disconnected".
 */
class DetectStaleBridgeConnections extends Command
{
    protected $signature = 'bridge:detect-stale {--threshold=120 : Seconds since last heartbeat to consider stale}';

    protected $description = 'Mark bridge connections as disconnected if no heartbeat received within threshold';

    public function handle(): int
    {
        $threshold = (int) $this->option('threshold');

        $stale = BridgeConnection::withoutGlobalScopes()
            ->where('status', BridgeConnectionStatus::Connected->value)
            ->where(function ($q) use ($threshold) {
                $q->where('last_seen_at', '<', now()->subSeconds($threshold))
                    ->orWhereNull('last_seen_at');
            })
            ->get();

        if ($stale->isEmpty()) {
            $this->components->info('No stale bridge connections found.');

            return self::SUCCESS;
        }

        foreach ($stale as $connection) {
            $connection->update([
                'status' => BridgeConnectionStatus::Disconnected,
                'disconnected_at' => now(),
            ]);

            Log::warning('DetectStaleBridgeConnections: marked connection as disconnected', [
                'connection_id' => $connection->id,
                'team_id' => $connection->team_id,
                'last_seen_at' => $connection->last_seen_at?->toISOString(),
                'label' => $connection->label,
            ]);

            $this->components->warn("Marked stale: {$connection->id} (team: {$connection->team_id}, last seen: {$connection->last_seen_at})");
        }

        $this->components->info("Cleaned up {$stale->count()} stale connection(s).");

        return self::SUCCESS;
    }
}
