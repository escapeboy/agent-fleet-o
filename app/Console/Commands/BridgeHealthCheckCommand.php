<?php

namespace App\Console\Commands;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BridgeHealthCheckCommand extends Command
{
    protected $signature = 'bridge:health-check';

    protected $description = 'Check health of HTTP-mode bridge connections and update their status';

    public function handle(): int
    {
        $connections = BridgeConnection::withoutGlobalScopes()
            ->whereNotNull('endpoint_url')
            ->where(function ($query) {
                $query->where('status', BridgeConnectionStatus::Connected->value)
                    ->orWhere('status', BridgeConnectionStatus::Reconnecting->value);
            })
            ->where(function ($query) {
                // Only ping connections not seen in the last 5 minutes
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes(5));
            })
            ->get();

        if ($connections->isEmpty()) {
            $this->line('No HTTP-mode bridge connections need health-checking.');

            return self::SUCCESS;
        }

        $this->line("Checking {$connections->count()} HTTP bridge connection(s)...");

        foreach ($connections as $connection) {
            $this->checkConnection($connection);
        }

        return self::SUCCESS;
    }

    private function checkConnection(BridgeConnection $connection): void
    {
        $healthUrl = rtrim($connection->endpoint_url, '/').'/health';
        $headers = [];

        if (! empty($connection->endpoint_secret)) {
            $headers['Authorization'] = 'Bearer '.$connection->endpoint_secret;
        }

        try {
            $response = Http::timeout(8)->withHeaders($headers)->get($healthUrl);
            $online = $response->successful();

            $newStatus = $online
                ? BridgeConnectionStatus::Connected
                : BridgeConnectionStatus::Disconnected;

            $connection->update([
                'status' => $newStatus,
                'last_seen_at' => $online ? now() : $connection->last_seen_at,
            ]);

            $label = $connection->label ?? $connection->endpoint_url;

            if ($online) {
                $this->line("  ✓ {$label} — online");
            } else {
                $this->warn("  ✗ {$label} — offline (HTTP {$response->status()})");
            }
        } catch (\Throwable $e) {
            $connection->update(['status' => BridgeConnectionStatus::Disconnected]);

            $label = $connection->label ?? $connection->endpoint_url;
            $this->warn("  ✗ {$label} — unreachable: {$e->getMessage()}");

            Log::debug('BridgeHealthCheckCommand: connection unreachable', [
                'connection_id' => $connection->id,
                'endpoint_url' => $connection->endpoint_url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
