<?php

namespace App\Console\Commands;

use App\Domain\Audit\Models\AuditEntry;
use Illuminate\Console\Command;

class CleanupAuditEntries extends Command
{
    protected $signature = 'audit:cleanup {--days=90 : Number of days to retain audit entries}';

    protected $description = 'Delete audit entries older than the retention period';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        // withoutGlobalScopes() ensures the query spans all teams, not just the current team.
        // This command runs in console/cron context where no team is authenticated.
        $deleted = AuditEntry::withoutGlobalScopes()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }
}
