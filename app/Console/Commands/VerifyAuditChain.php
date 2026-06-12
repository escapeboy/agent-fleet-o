<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditChainService;
use Illuminate\Console\Command;

class VerifyAuditChain extends Command
{
    protected $signature = 'audit:verify-chain {--team= : Verify a single team chain (team UUID)}';

    protected $description = 'Verify the integrity of the tamper-evident audit hash chains';

    public function handle(AuditChainService $chain): int
    {
        $reports = $chain->verifyChain($this->option('team'));

        if ($reports === []) {
            $this->info('No chained audit entries found.');

            return self::SUCCESS;
        }

        $broken = false;

        foreach ($reports as $report) {
            if ($report['status'] === 'ok') {
                $this->info("[{$report['group']}] OK — {$report['checked']} entries verified.");
            } else {
                $broken = true;
                $this->error("[{$report['group']}] BROKEN at entry {$report['first_break_id']} — chain integrity violated.");
            }

            if ($report['unchained_stragglers'] > 0) {
                $this->warn("[{$report['group']}] {$report['unchained_stragglers']} unchained straggler(s) below the chain cursor (settle-window edge).");
            }
        }

        return $broken ? self::FAILURE : self::SUCCESS;
    }
}
