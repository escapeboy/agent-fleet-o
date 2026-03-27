<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use Illuminate\Support\Facades\DB;

class ClaimNextTaskAction
{
    /**
     * Atomically claim the next pending task for the given agent.
     * Uses a database transaction with pessimistic locking to prevent double-claims.
     *
     * @return CrewTaskExecution|null The claimed task, or null if no tasks are available.
     */
    public function execute(CrewExecution $execution, Agent $agent): ?CrewTaskExecution
    {
        return DB::transaction(function () use ($execution, $agent) {
            // Find the first Pending task ordered by sort_order.
            // Tasks with unresolved dependencies have Blocked status, so only Pending tasks are ready.
            $task = CrewTaskExecution::where('crew_execution_id', $execution->id)
                ->where('status', CrewTaskStatus::Pending)
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->first();

            if (! $task) {
                return null;
            }

            $task->update([
                'status' => CrewTaskStatus::Assigned,
                'agent_id' => $agent->id,
                'claimed_at' => now(),
            ]);

            return $task->fresh();
        });
    }
}
