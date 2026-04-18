<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use Illuminate\Console\Command;

class CleanupBugReportsCommand extends Command
{
    protected $signature = 'signals:cleanup-bug-reports';

    protected $description = 'Delete bug report signals older than the configured retention period (default 90 days)';

    public function handle(): int
    {
        $deleted = 0;

        Team::query()->each(function (Team $team) use (&$deleted) {
            $retentionDays = (int) ($team->settings['bug_report_retention_days'] ?? 90);
            $cutoff = now()->subDays($retentionDays);

            $signals = Signal::withoutGlobalScopes()
                ->where('team_id', $team->id)
                ->where('source_type', 'bug_report')
                ->where('created_at', '<', $cutoff)
                ->get();

            foreach ($signals as $signal) {
                $signal->clearMediaCollection('bug_report_files');
                $signal->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} expired bug reports.");

        return self::SUCCESS;
    }
}
