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

        // Dispatch one job per (team, Sentry project). The job triages signals
        // scoped to its integration's project_id, so two integrations on the same
        // team but different projects are disjoint and must BOTH run — each routes
        // its PRs to its own target_repository. Integrations without a project_id
        // triage org-wide, so they dedup per team to avoid double-triage races.
        $dispatchedKeys = [];
        foreach ($integrations as $integration) {
            $project = $integration->config['project_id'] ?? null;
            $key = $integration->team_id.'|'.(($project !== null && $project !== '') ? (string) $project : '__org_wide__');
            if (in_array($key, $dispatchedKeys, true)) {
                continue;
            }
            $dispatchedKeys[] = $key;
            RunSentryWatchdogJob::dispatch($integration->id);
            $this->info("Dispatched Sentry Watchdog for: {$integration->name}");
        }

        return self::SUCCESS;
    }
}
