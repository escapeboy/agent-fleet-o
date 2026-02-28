<?php

namespace App\Console\Commands;

use App\Domain\System\Services\VersionCheckService;
use Illuminate\Console\Command;

class CheckForUpdates extends Command
{
    protected $signature = 'system:check-updates {--force : Bypass cache and force a fresh GitHub API call}';

    protected $description = 'Check GitHub Releases for a newer version of FleetQ';

    public function handle(VersionCheckService $service): int
    {
        if (! $service->isCheckEnabled()) {
            $this->info('Update checks are disabled (update_check_enabled = false).');

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $this->info('Forcing fresh check (bypassing cache)...');
            $service->forceRefresh();
        }

        $installed = $service->getInstalledVersion();
        $latest = $service->getLatestVersion();

        $this->info("Installed version: {$installed}");
        $this->info('Latest version:    '.($latest ?? 'unknown (check failed)'));

        if ($latest === null) {
            $this->warn('Could not retrieve latest version from GitHub. Check network or rate limits.');

            return self::SUCCESS;
        }

        if ($service->isUpdateAvailable()) {
            $info = $service->getUpdateInfo();
            $this->warn("A new version is available: {$latest}");

            if ($info['release_url']) {
                $this->line("Release notes: {$info['release_url']}");
            }
        } else {
            $this->info('You are running the latest version.');
        }

        return self::SUCCESS;
    }
}
