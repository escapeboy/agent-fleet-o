<?php

namespace App\Console\Commands;

use App\Domain\Skill\Jobs\ClusterExperimentsForSkillsJob;
use App\Domain\Skill\Services\AutoSkillCreationService;
use Illuminate\Console\Command;

class ClusterExperimentsForSkillsCommand extends Command
{
    protected $signature = 'skills:auto-generate {--dry-run : Simulate without creating records}';

    protected $description = 'Cluster completed experiments by semantic similarity and generate draft skills for recurring patterns';

    public function handle(AutoSkillCreationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in dry-run mode — no skills will be created.');
            $count = $service->run(dryRun: true);
            $this->info("Would have created {$count} skill(s). Check logs for details.");
        } else {
            ClusterExperimentsForSkillsJob::dispatch();
            $this->info('ClusterExperimentsForSkillsJob dispatched to queue.');
        }

        return self::SUCCESS;
    }
}
