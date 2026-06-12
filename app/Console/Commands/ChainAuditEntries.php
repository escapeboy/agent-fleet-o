<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditChainService;
use Illuminate\Console\Command;

class ChainAuditEntries extends Command
{
    protected $signature = 'audit:chain {--batch= : Max entries to chain per group in this run}';

    protected $description = 'Link pending audit entries into per-team tamper-evident hash chains';

    public function handle(AuditChainService $chain): int
    {
        $batch = $this->option('batch') !== null ? (int) $this->option('batch') : null;

        $counts = $chain->chainPending($batch);

        if ($counts === []) {
            $this->info('No pending audit entries to chain.');

            return self::SUCCESS;
        }

        foreach ($counts as $group => $count) {
            $this->info("Chained {$count} entries for group {$group}.");
        }

        return self::SUCCESS;
    }
}
