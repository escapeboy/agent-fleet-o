<?php

namespace App\Console\Commands;

use App\Domain\Memory\Actions\AuditMemoryProposalsAction;
use Illuminate\Console\Command;

class AuditPendingMemoryProposalsCommand extends Command
{
    protected $signature = 'memory:audit-proposals
        {--team= : Restrict to a single team UUID}
        {--limit=200 : Max proposals to process this run}';

    protected $description = 'Heuristic auditor that auto-approves/auto-rejects pending memory proposals.';

    public function handle(AuditMemoryProposalsAction $auditor): int
    {
        $teamId = $this->option('team') ?: null;
        $limit = (int) $this->option('limit');

        $result = $auditor->execute(teamId: $teamId, limit: $limit > 0 ? $limit : 200);

        $this->components->info(sprintf(
            'Audited proposals — approved: %d, rejected: %d, queued: %d',
            $result['approved'],
            $result['rejected'],
            $result['queued'],
        ));

        return self::SUCCESS;
    }
}
