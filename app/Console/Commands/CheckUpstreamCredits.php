<?php

namespace App\Console\Commands;

use App\Domain\Budget\Actions\CheckUpstreamCreditRunwayAction;
use Illuminate\Console\Command;

class CheckUpstreamCredits extends Command
{
    protected $signature = 'credits:check-upstream {--dry-run : Compute and print runway without sending alerts}';

    protected $description = 'Check upstream (platform-funded) credit runway per sub-program/provider and email the platform owner when low.';

    public function handle(CheckUpstreamCreditRunwayAction $action): int
    {
        $summaries = $action->execute(dryRun: (bool) $this->option('dry-run'));

        if ($summaries === []) {
            $this->info('No upstream budgets configured (or alerts disabled). Nothing to check.');

            return self::SUCCESS;
        }

        $this->table(
            ['Sub-program', 'Provider', 'Remaining', 'Avg/day (7d)', 'Days left', 'Bucket', 'Alerted'],
            array_map(fn (array $s): array => [
                $s['sub_program'],
                $s['provider'],
                number_format($s['remaining']),
                number_format($s['daily_avg_7d']),
                $s['days_until_depletion'] ?? '∞',
                $s['alert_bucket'] ?? '—',
                $s['alerted'] ? 'yes' : 'no',
            ], $summaries),
        );

        return self::SUCCESS;
    }
}
