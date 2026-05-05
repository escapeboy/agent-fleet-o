<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\WorldModel\Jobs\BuildWorldModelDigestJob;
use Illuminate\Console\Command;

class RebuildWorldModelsCommand extends Command
{
    protected $signature = 'worldmodel:rebuild
        {--team= : Rebuild for a single team ID (otherwise all teams)}
        {--sync : Run inline instead of dispatching to the queue}';

    protected $description = 'Rebuild per-team world-model digests from recent signals/experiments/memories';

    public function handle(): int
    {
        $teamId = $this->option('team');

        $query = Team::withoutGlobalScopes()->query();
        if ($teamId) {
            $query->where('id', $teamId);
        }

        $count = 0;
        $query->each(function (Team $team) use (&$count) {
            if ($this->option('sync')) {
                dispatch_sync(new BuildWorldModelDigestJob($team->id));
            } else {
                BuildWorldModelDigestJob::dispatch($team->id);
            }
            $count++;
        });

        $this->info("Dispatched world-model rebuilds for {$count} team(s).");

        return self::SUCCESS;
    }
}
