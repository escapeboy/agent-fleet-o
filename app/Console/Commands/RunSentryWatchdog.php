<?php

namespace App\Console\Commands;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Jobs\RunSentryWatchdogJob;
use Illuminate\Console\Command;

/**
 * Dispatches the Sentry Watchdog batch for every active Sentry integration that
 * has opted in via config['watchdog_enabled']. Scheduled twice daily.
 */
class RunSentryWatchdog extends Command
{
    protected $signature = 'sentry:watchdog {--project= : Only watch integrations whose name contains this value}';

    protected $description = 'Triage Sentry issues for each enabled Sentry integration.';

    public function handle(): int
    {
        $project = $this->option('project');

        $integrations = Integration::withoutGlobalScopes()
            ->where('driver', 'sentry')
            ->where('status', IntegrationStatus::Active)
            ->when(
                $project,
                fn ($query) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower((string) $project).'%']),
            )
            ->get()
            ->filter(fn (Integration $integration) => (bool) ($integration->config['watchdog_enabled'] ?? false));

        if ($integrations->isEmpty()) {
            $this->info('No enabled Sentry integrations to watch.');

            return self::SUCCESS;
        }

        // The job triages every Sentry signal for a team, so dispatch one job
        // per team — a second job for the same team would double-triage the
        // same signals (the two jobs race and re-spend LLM calls).
        $dispatchedTeams = [];
        foreach ($integrations as $integration) {
            if (in_array($integration->team_id, $dispatchedTeams, true)) {
                continue;
            }
            $dispatchedTeams[] = $integration->team_id;
            RunSentryWatchdogJob::dispatch($integration->id);
            $this->info("Dispatched Sentry Watchdog for: {$integration->name}");
        }

        return self::SUCCESS;
    }
}
