<?php

namespace App\Console\Commands;

use FleetQ\BorunaAudit\Enums\DecisionStatus;
use FleetQ\BorunaAudit\Models\AuditableDecision;
use FleetQ\BorunaAudit\Services\BundleVerifier;
use Illuminate\Console\Command;

class VerifyBorunaBundlesCommand extends Command
{
    protected $signature = 'boruna:verify
                            {--tenant= : Team UUID or "all"}
                            {--sample=20 : Number of bundles to sample}
                            {--verbose}';

    protected $description = 'Verify a random sample of Boruna evidence bundles for cryptographic integrity.';

    public function handle(BundleVerifier $verifier): int
    {
        $tenant = $this->option('tenant');
        $sample = (int) $this->option('sample');

        if ($tenant === null) {
            $this->error('Provide --tenant=<uuid> or --tenant=all');

            return self::FAILURE;
        }

        $query = AuditableDecision::where('status', DecisionStatus::Completed)
            ->whereNotNull('bundle_path')
            ->inRandomOrder()
            ->limit($sample);

        if ($tenant !== 'all') {
            $query->where('team_id', $tenant);
        }

        $decisions = $query->get();

        if ($decisions->isEmpty()) {
            $this->info('No completed decisions with bundles found.');

            return self::SUCCESS;
        }

        $rows = [];
        $anyFailed = false;

        foreach ($decisions as $decision) {
            $result = $verifier->verify($decision, $decision->team_id);

            $rows[] = [
                substr($decision->id, 0, 8),
                $decision->workflow_name,
                $result->passed ? 'PASSED' : 'FAILED',
                $result->errorMessage ?? '—',
            ];

            if (! $result->passed) {
                $anyFailed = true;
            }
        }

        $this->table(['ID', 'Workflow', 'Result', 'Error'], $rows);

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }
}
