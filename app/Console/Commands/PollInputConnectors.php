<?php

namespace App\Console\Commands;

use App\Domain\Signal\Connectors\ApiPollingConnector;
use App\Domain\Signal\Connectors\CalendarConnector;
use App\Domain\Signal\Connectors\DatadogAlertConnector;
use App\Domain\Signal\Connectors\GitHubIssuesConnector;
use App\Domain\Signal\Connectors\ImapConnector;
use App\Domain\Signal\Connectors\JiraConnector;
use App\Domain\Signal\Connectors\LinearConnector;
use App\Domain\Signal\Connectors\PagerDutyConnector;
use App\Domain\Signal\Connectors\RssConnector;
use App\Domain\Signal\Connectors\SentryAlertConnector;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Models\Connector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollInputConnectors extends Command
{
    protected $signature = 'connectors:poll {--driver= : Driver to poll (rss, imap, api_polling, calendar, github_issues, jira, linear, sentry, datadog, pagerduty). Polls all if omitted.}';

    protected $description = 'Poll active input connectors for new signals';

    /** @var array<string, class-string<InputConnectorInterface>> */
    private array $driverMap = [
        'rss' => RssConnector::class,
        'imap' => ImapConnector::class,
        'api_polling' => ApiPollingConnector::class,
        'calendar' => CalendarConnector::class,
        'github_issues' => GitHubIssuesConnector::class,
        'jira' => JiraConnector::class,
        'linear' => LinearConnector::class,
        'sentry' => SentryAlertConnector::class,
        'datadog' => DatadogAlertConnector::class,
        'pagerduty' => PagerDutyConnector::class,
    ];

    public function handle(): int
    {
        $driver = $this->option('driver');
        $drivers = $driver ? [$driver] : array_keys($this->driverMap);

        $totalSignals = 0;
        $totalConnectors = 0;

        foreach ($drivers as $driverName) {
            if (! isset($this->driverMap[$driverName])) {
                $this->warn("Unknown driver: {$driverName}");

                continue;
            }

            $connectors = Connector::where('type', 'input')
                ->where('driver', $driverName)
                ->where('status', 'active')
                ->get();

            if ($connectors->isEmpty()) {
                continue;
            }

            /** @var InputConnectorInterface $connectorInstance */
            $connectorInstance = app($this->driverMap[$driverName]);

            foreach ($connectors as $connector) {
                $this->info("Polling: {$connector->name} ({$connector->driver})");
                $totalConnectors++;

                try {
                    $config = $connector->config ?? [];
                    $signals = $connectorInstance->poll($config);

                    $count = count($signals);
                    $totalSignals += $count;

                    // Update connector config with state tracking (UIDs, cursors, etc.)
                    $updatedConfig = $this->getUpdatedConfig($connectorInstance, $config, $signals);

                    $connector->update([
                        'config' => $updatedConfig,
                        'last_success_at' => now(),
                        'last_error_message' => null,
                    ]);

                    $this->info("  → {$count} new signal(s) ingested");
                } catch (\Throwable $e) {
                    $connector->update([
                        'last_error_at' => now(),
                        'last_error_message' => mb_substr($e->getMessage(), 0, 500),
                    ]);

                    Log::error('PollInputConnectors: Error polling connector', [
                        'connector_id' => $connector->id,
                        'driver' => $connector->driver,
                        'error' => $e->getMessage(),
                    ]);

                    $this->error("  → Error: {$e->getMessage()}");
                }
            }
        }

        if ($totalConnectors === 0) {
            $this->info('No active input connectors found.');
        } else {
            $this->info("Total: {$totalSignals} new signal(s) across {$totalConnectors} connector(s).");
        }

        return self::SUCCESS;
    }

    private function getUpdatedConfig(InputConnectorInterface $connector, array $config, array $signals): array
    {
        if (method_exists($connector, 'getUpdatedConfig')) {
            return $connector->getUpdatedConfig($config, $signals);
        }

        return $config;
    }
}
