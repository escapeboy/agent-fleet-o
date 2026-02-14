<?php

namespace App\Console\Commands;

use App\Domain\Memory\Actions\PruneMemoriesAction;
use Illuminate\Console\Command;

class PruneMemoriesCommand extends Command
{
    protected $signature = 'memories:prune {--days= : Override TTL days from config}';

    protected $description = 'Prune agent memories older than the configured TTL';

    public function handle(PruneMemoriesAction $action): int
    {
        $days = $this->option('days') ? (int) $this->option('days') : null;

        $count = $action->execute($days);

        $this->info("Pruned {$count} memories.");

        return self::SUCCESS;
    }
}
