<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\KnowledgeGraph\Actions\BuildKgCommunitiesAction;
use App\Domain\Shared\Models\Team;
use Illuminate\Console\Command;

class BuildKgCommunitiesCommand extends Command
{
    protected $signature = 'kg:build-communities
                            {--team-id= : Specific team ID to process}
                            {--min-size=2 : Minimum community size}';

    protected $description = 'Build Louvain community clusters for the Knowledge Graph';

    public function handle(BuildKgCommunitiesAction $action): int
    {
        $teamId = $this->option('team-id');
        $minSize = (int) $this->option('min-size');

        $teams = $teamId
            ? Team::where('id', $teamId)->get()
            : Team::all();

        foreach ($teams as $team) {
            $this->line("Processing team {$team->id}...");

            try {
                $action->execute($team->id, $minSize);
                $this->info('  Done.');
            } catch (\Throwable $e) {
                $this->warn("  Failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
