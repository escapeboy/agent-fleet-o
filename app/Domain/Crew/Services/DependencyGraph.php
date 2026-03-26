<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Exceptions\CyclicDependencyException;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;

class DependencyGraph
{
    /**
     * Detect cycles in the depends_on graph for a given crew execution.
     *
     * Simulates adding $newDependsOn to $taskId and runs DFS cycle detection
     * across all tasks in the same execution. Throws if a cycle is detected.
     *
     * @param  string[]  $newDependsOn  UUIDs of tasks this task will depend on
     *
     * @throws CyclicDependencyException
     */
    public function detectCycle(
        string $crewExecutionId,
        string $taskId,
        array $newDependsOn,
        string $teamId = '',
    ): void {
        // Build adjacency map from the current DB state, then simulate the new edges
        $graph = $this->buildAdjacencyMap($crewExecutionId, $teamId);

        // Merge the proposed new dependencies into the graph
        $graph[$taskId] = array_unique(
            array_merge($graph[$taskId] ?? [], $newDependsOn),
        );

        // Run DFS cycle detection from every node
        $visited = [];
        $inStack = [];

        foreach (array_keys($graph) as $node) {
            if (! isset($visited[$node])) {
                if ($this->hasCycleDfs($node, $graph, $visited, $inStack)) {
                    throw new CyclicDependencyException($taskId, $crewExecutionId);
                }
            }
        }
    }

    /**
     * Auto-unblock dependent tasks when a task reaches a satisfied terminal state.
     *
     * Must be called inside an existing DB::transaction. Finds all Blocked tasks
     * in the same execution that list $completedTask->id in their depends_on array.
     * For each, checks whether ALL their dependencies are now terminal
     * (Validated or Skipped). If so, transitions the task to Pending and
     * dispatches ExecuteCrewTaskJob.
     */
    public function autoUnblock(
        CrewExecution $execution,
        CrewTaskExecution $completedTask,
    ): void {
        // Only unblock when the triggering task has reached a satisfied terminal state
        if (! in_array($completedTask->status, [CrewTaskStatus::Validated, CrewTaskStatus::Skipped], true)) {
            return;
        }

        // Find all Blocked tasks in this execution that depend on the completed task
        $dependents = CrewTaskExecution::query()
            ->where('crew_execution_id', $execution->id)
            ->where('status', CrewTaskStatus::Blocked)
            ->whereJsonContains('depends_on', $completedTask->id)
            ->get();

        foreach ($dependents as $dependent) {
            // Re-acquire the row with a pessimistic lock to prevent duplicate dispatch
            // when two tasks complete concurrently and both try to unblock the same dependent.
            $locked = CrewTaskExecution::query()
                ->where('id', $dependent->id)
                ->where('status', CrewTaskStatus::Blocked)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Another process has already transitioned this task
                continue;
            }

            $depIds = $locked->depends_on ?? [];

            if (empty($depIds)) {
                continue;
            }

            // Check if any dependency is still not in a satisfied terminal state
            $hasPendingDep = CrewTaskExecution::query()
                ->whereIn('id', $depIds)
                ->whereNotIn('status', [
                    CrewTaskStatus::Validated->value,
                    CrewTaskStatus::Skipped->value,
                ])
                ->exists();

            if (! $hasPendingDep) {
                $locked->update(['status' => CrewTaskStatus::Pending]);

                ExecuteCrewTaskJob::dispatch(
                    crewExecutionId: $execution->id,
                    taskExecutionId: $locked->id,
                    teamId: $execution->team_id,
                );
            }
        }
    }

    /**
     * Build a node → [depends_on_ids] adjacency map for all tasks in an execution.
     *
     * @return array<string, string[]>
     */
    private function buildAdjacencyMap(string $crewExecutionId, string $teamId = ''): array
    {
        $tasks = CrewTaskExecution::query()
            ->where('crew_execution_id', $crewExecutionId)
            ->when($teamId !== '', fn ($q) => $q->where('team_id', $teamId))
            ->get(['id', 'depends_on']);

        $graph = [];

        foreach ($tasks as $task) {
            $graph[$task->id] = $task->depends_on ?? [];
        }

        return $graph;
    }

    /**
     * DFS back-edge detection. Returns true if a cycle is found reachable from $node.
     *
     * @param  array<string, string[]>  $graph
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $inStack
     */
    private function hasCycleDfs(
        string $node,
        array $graph,
        array &$visited,
        array &$inStack,
    ): bool {
        $visited[$node] = true;
        $inStack[$node] = true;

        foreach ($graph[$node] ?? [] as $neighbour) {
            if (! isset($visited[$neighbour])) {
                if ($this->hasCycleDfs($neighbour, $graph, $visited, $inStack)) {
                    return true;
                }
            } elseif (isset($inStack[$neighbour]) && $inStack[$neighbour]) {
                // Back edge — cycle detected
                return true;
            }
        }

        $inStack[$node] = false;

        return false;
    }
}
