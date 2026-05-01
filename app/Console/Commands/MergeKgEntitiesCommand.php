<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\KnowledgeGraph\Actions\DetectDuplicateEntitiesAction;
use App\Domain\KnowledgeGraph\Actions\MergeEntitiesAction;
use App\Domain\Shared\Models\Team;
use Illuminate\Console\Command;

class MergeKgEntitiesCommand extends Command
{
    protected $signature = 'kg:merge-entities
                            {--team-id= : Specific team ID to process}
                            {--threshold=0.85 : Similarity threshold (0.5–1.0)}
                            {--dry-run : Show candidates without merging}';

    protected $description = 'Detect and merge near-duplicate Knowledge Graph entities';

    public function handle(DetectDuplicateEntitiesAction $detect, MergeEntitiesAction $merge): int
    {
        $threshold = (float) $this->option('threshold');
        $dryRun = (bool) $this->option('dry-run');
        $teamId = $this->option('team-id');

        $teams = $teamId
            ? Team::where('id', $teamId)->get()
            : Team::all();

        $totalMerged = 0;

        foreach ($teams as $team) {
            $candidates = $detect->execute($team->id, $threshold);

            if (empty($candidates)) {
                $this->line("Team {$team->id}: no duplicate candidates found.");

                continue;
            }

            $this->line("Team {$team->id}: found ".count($candidates).' candidate pair(s).');

            foreach ($candidates as $candidate) {
                $this->line(sprintf(
                    '  [%.2f] canonical=%s  duplicate=%s  reason: %s',
                    $candidate['confidence'],
                    $candidate['canonical_id'],
                    $candidate['duplicate_id'],
                    $candidate['reason'],
                ));

                if (! $dryRun) {
                    try {
                        $merge->execute($team->id, $candidate['canonical_id'], $candidate['duplicate_id']);
                        $totalMerged++;
                    } catch (\Throwable $e) {
                        $this->warn("  Failed: {$e->getMessage()}");
                    }
                }
            }
        }

        if (! $dryRun) {
            $this->info("Merged {$totalMerged} duplicate entity pair(s).");
        } else {
            $this->info('Dry-run complete — no changes made.');
        }

        return self::SUCCESS;
    }
}
