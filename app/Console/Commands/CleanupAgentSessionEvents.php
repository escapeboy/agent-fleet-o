<?php

namespace App\Console\Commands;

use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\AgentSession\Models\AgentSession;
use App\Domain\AgentSession\Models\AgentSessionEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CleanupAgentSessionEvents extends Command
{
    protected $signature = 'agent-session-events:cleanup
        {--days=90 : Number of days to retain agent session events}
        {--dry-run : Only count rows that would be deleted, do not delete}
        {--chunk=1000 : Rows deleted per loop iteration}';

    protected $description = 'Delete agent_session_events older than the retention period and remove orphaned terminal sessions whose events are all gone.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $eventsDeleted = $this->cleanupEvents($cutoff, $chunk, $dryRun);
        $this->info(($dryRun ? '[dry-run] ' : '')."Removed {$eventsDeleted} agent_session_events older than {$days} days.");

        $sessionsDeleted = $this->cleanupOrphanTerminalSessions($cutoff, $dryRun);
        $this->info(($dryRun ? '[dry-run] ' : '')."Removed {$sessionsDeleted} orphan terminal agent_sessions.");

        return self::SUCCESS;
    }

    private function cleanupEvents(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        if ($dryRun) {
            return AgentSessionEvent::withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->count();
        }

        $totalDeleted = 0;
        do {
            $deleted = AgentSessionEvent::withoutGlobalScopes()
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $totalDeleted += $deleted;
        } while ($deleted > 0);

        return $totalDeleted;
    }

    private function cleanupOrphanTerminalSessions(Carbon $cutoff, bool $dryRun): int
    {
        $terminalStatuses = [
            AgentSessionStatus::Completed->value,
            AgentSessionStatus::Cancelled->value,
            AgentSessionStatus::Failed->value,
        ];

        $query = AgentSession::withoutGlobalScopes()
            ->whereIn('status', $terminalStatuses)
            ->where('ended_at', '<', $cutoff)
            ->whereDoesntHave('events');

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }
}
