<?php

namespace App\Console\Commands;

use App\Domain\Signal\Actions\SendSentryWatchdogDigestAction;
use App\Domain\Signal\Models\SentryWatchdogRun;
use Illuminate\Console\Command;

/**
 * One Sentry Watchdog digest per team for the finished runs not yet digested. Scheduled after sentry:watchdog.
 * A run still going when this runs is picked up by the next digest.
 */
class SendSentryWatchdogDigest extends Command
{
    protected $signature = 'sentry:watchdog-digest';

    protected $description = 'Send one Sentry Watchdog digest per team for the finished runs.';

    public function handle(SendSentryWatchdogDigestAction $digest): int
    {
        $runs = SentryWatchdogRun::withoutGlobalScopes()
            ->with(['integration' => fn ($q) => $q->withoutGlobalScopes()])
            ->whereNotNull('finished_at')
            ->whereNull('digest_sent_at')
            ->orderBy('started_at')
            ->get();

        foreach ($runs->groupBy('team_id') as $teamId => $teamRuns) {
            $sent = $digest->execute((string) $teamId, $teamRuns);
            $this->info("Team {$teamId}: {$teamRuns->count()} runs, ".($sent ? 'digest sent' : 'nothing to send'));
        }

        return self::SUCCESS;
    }
}
