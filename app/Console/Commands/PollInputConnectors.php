<?php

namespace App\Console\Commands;

use App\Domain\Signal\Connectors\RssConnector;
use App\Models\Connector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollInputConnectors extends Command
{
    protected $signature = 'connectors:poll {--driver=rss : Driver to poll (rss)}';

    protected $description = 'Poll active input connectors for new signals';

    public function handle(RssConnector $rssConnector): int
    {
        $driver = $this->option('driver');

        $connectors = Connector::where('type', 'input')
            ->where('driver', $driver)
            ->where('status', 'active')
            ->get();

        if ($connectors->isEmpty()) {
            $this->info("No active {$driver} connectors found.");
            return self::SUCCESS;
        }

        $totalSignals = 0;

        foreach ($connectors as $connector) {
            $this->info("Polling: {$connector->name} ({$connector->driver})");

            try {
                $config = $connector->config ?? [];
                $signals = $rssConnector->poll($config);

                $count = count($signals);
                $totalSignals += $count;

                $connector->update([
                    'last_success_at' => now(),
                    'last_error_message' => null,
                ]);

                $this->info("  → {$count} new signal(s) ingested");
            } catch (\Throwable $e) {
                $connector->update([
                    'last_error_at' => now(),
                    'last_error_message' => $e->getMessage(),
                ]);

                Log::error('PollInputConnectors: Error polling connector', [
                    'connector_id' => $connector->id,
                    'driver' => $connector->driver,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  → Error: {$e->getMessage()}");
            }
        }

        $this->info("Total: {$totalSignals} new signal(s) across {$connectors->count()} connector(s).");

        return self::SUCCESS;
    }
}
