<?php

namespace App\Console\Commands;

use App\Domain\Memory\Models\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneMemoriesByCapacityCommand extends Command
{
    protected $signature = 'memory:prune {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Remove lowest-importance memories for teams that exceed their per-team capacity limit';

    public function handle(): int
    {
        $maxPerTeam = (int) config('agent.memory.max_per_team', 10000);
        $batchSize = (int) config('agent.memory.prune_batch_size', 500);

        if ($maxPerTeam <= 0) {
            $this->info('memory:prune skipped — max_per_team is 0 (disabled).');

            return self::SUCCESS;
        }

        $isDryRun = (bool) $this->option('dry-run');

        // Find all teams that exceed the cap
        $overCapacity = Memory::withoutGlobalScopes()
            ->select('team_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('team_id')
            ->havingRaw('COUNT(*) > ?', [$maxPerTeam])
            ->get();

        if ($overCapacity->isEmpty()) {
            $this->info('memory:prune — no teams over capacity.');

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        // effective_importance = LEAST(importance + LN(1 + retrieval_count) * 0.15, 1.0)
        $effectiveImportanceSql = 'LEAST(COALESCE(importance, 0.5) + LN(1 + COALESCE(retrieval_count, 0)) * 0.15, 1.0)';

        foreach ($overCapacity as $row) {
            $teamId = $row->team_id;
            $excess = (int) $row->cnt - $maxPerTeam;

            if ($excess <= 0) {
                continue;
            }

            $deleted = 0;

            // Delete in batches to avoid lock contention
            while ($excess > 0) {
                $limit = min($batchSize, $excess);

                $ids = Memory::withoutGlobalScopes()
                    ->where('team_id', $teamId)
                    ->selectRaw("id, ({$effectiveImportanceSql}) AS eff_importance")
                    ->orderByRaw("({$effectiveImportanceSql}) ASC")
                    ->orderBy('created_at', 'ASC')
                    ->limit($limit)
                    ->pluck('id');

                if ($ids->isEmpty()) {
                    break;
                }

                if (! $isDryRun) {
                    Memory::withoutGlobalScopes()->whereIn('id', $ids)->delete();
                }

                $deleted += $ids->count();
                $excess -= $ids->count();
            }

            $action = $isDryRun ? 'Would delete' : 'Deleted';
            $this->line("{$action} {$deleted} memories for team {$teamId} (was {$row->cnt}, cap {$maxPerTeam}).");

            if (! $isDryRun && $deleted > 0) {
                Log::info('memory:prune capacity eviction', [
                    'team_id' => $teamId,
                    'deleted' => $deleted,
                    'was' => $row->cnt,
                    'cap' => $maxPerTeam,
                ]);
            }

            $totalDeleted += $deleted;
        }

        $action = $isDryRun ? 'Would prune' : 'Pruned';
        $this->info("{$action} {$totalDeleted} memories total across {$overCapacity->count()} team(s).");

        return self::SUCCESS;
    }
}
