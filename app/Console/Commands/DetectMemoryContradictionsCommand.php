<?php

namespace App\Console\Commands;

use App\Domain\Memory\Actions\DetectMemoryContradictionsAction;
use Illuminate\Console\Command;

class DetectMemoryContradictionsCommand extends Command
{
    protected $signature = 'memory:detect-contradictions
        {--team= : Restrict the scan to a single team UUID}
        {--force : Run even when the scheduled scan is disabled in config}';

    protected $description = 'Scan the memory corpus for contradicting belief pairs and flag them for human review.';

    public function handle(DetectMemoryContradictionsAction $action): int
    {
        if (! config('memory.contradiction_scan.enabled', false) && ! $this->option('force')) {
            $this->components->info('Contradiction scan is disabled (memory.contradiction_scan.enabled). Use --force to run anyway.');

            return self::SUCCESS;
        }

        $result = $action->execute(teamId: $this->option('team') ?: null);

        $this->components->info(sprintf(
            'Contradiction scan — teams: %d, pairs evaluated: %d, contradictions flagged: %d',
            $result['teams_scanned'],
            $result['pairs_evaluated'],
            $result['contradictions_found'],
        ));

        return self::SUCCESS;
    }
}
