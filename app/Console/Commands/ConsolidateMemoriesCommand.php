<?php

namespace App\Console\Commands;

use App\Domain\Memory\Jobs\ConsolidateAgentMemoriesJob;
use App\Domain\Memory\Models\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

class ConsolidateMemoriesCommand extends Command
{
    protected $signature = 'memories:consolidate
                            {--agent= : Consolidate memories for a specific agent}
                            {--team= : Consolidate memories for a specific team}';

    protected $description = 'Consolidate similar agent memories into merged summaries';

    public function handle(): int
    {
        if (! config('memory.consolidation.enabled', true)) {
            $this->info('Memory consolidation is disabled.');

            return self::SUCCESS;
        }

        $minMemories = config('memory.consolidation.min_memories_per_agent', 50);

        $query = Memory::withoutGlobalScopes()
            ->select('agent_id', 'team_id', DB::raw('COUNT(*) as count'))
            ->groupBy('agent_id', 'team_id')
            ->havingRaw('COUNT(*) >= ?', [$minMemories]);

        if ($agentId = $this->option('agent')) {
            $query->where('agent_id', $agentId);
        }

        if ($teamId = $this->option('team')) {
            $query->where('team_id', $teamId);
        }

        $agents = $query->get();

        if ($agents->isEmpty()) {
            $this->info('No agents with enough memories to consolidate.');

            return self::SUCCESS;
        }

        $this->info("Dispatching consolidation for {$agents->count()} agent(s)...");

        $jobs = $agents->map(fn ($row) => new ConsolidateAgentMemoriesJob(
            agentId: $row->agent_id,
            teamId: $row->team_id,
        ))->all();

        Bus::batch($jobs)
            ->name('memory:consolidate:'.now()->toDateString())
            ->onQueue('ai-calls')
            ->allowFailures()
            ->dispatch();

        $this->info('Consolidation batch dispatched.');

        return self::SUCCESS;
    }
}
