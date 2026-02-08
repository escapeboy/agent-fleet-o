<?php

namespace App\Console\Commands;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Outbound\Models\OutboundAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Notifications\WeeklyDigestNotification;
use App\Domain\Signal\Models\Signal;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SendWeeklyDigest extends Command
{
    protected $signature = 'digest:send-weekly';

    protected $description = 'Send a weekly activity digest email to all team owners';

    public function handle(): int
    {
        $since = now()->subWeek();
        $sent = 0;

        Team::query()->with('owner')->chunk(100, function ($teams) use ($since, &$sent) {
            foreach ($teams as $team) {
                $owner = $team->owner;
                if (! $owner) {
                    continue;
                }

                $experimentsCreated = Experiment::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->count();

                $experimentsCompleted = Experiment::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('status', 'completed')
                    ->where('updated_at', '>=', $since)
                    ->count();

                $outboundSent = OutboundAction::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->count();

                $signalsIngested = Signal::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->count();

                $budgetSpent = CreditLedger::withoutGlobalScopes()
                    ->where('team_id', $team->id)
                    ->where('created_at', '>=', $since)
                    ->where('type', LedgerType::Deduction)
                    ->sum('amount');

                // Skip teams with zero activity
                if ($experimentsCreated + $outboundSent + $signalsIngested === 0) {
                    continue;
                }

                $owner->notify(new WeeklyDigestNotification(
                    team: $team,
                    experimentsCreated: $experimentsCreated,
                    experimentsCompleted: $experimentsCompleted,
                    outboundSent: $outboundSent,
                    signalsIngested: $signalsIngested,
                    budgetSpentCents: (int) abs($budgetSpent),
                ));

                $sent++;
            }
        });

        $this->info("Sent {$sent} weekly digest(s).");

        return self::SUCCESS;
    }
}
