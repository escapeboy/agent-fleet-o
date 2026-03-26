<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\CrewTaskExecution;
use Illuminate\Support\Collection;

class TaskDependencyResolver
{
    /**
     * Return tasks that are ready to execute — all dependencies validated.
     *
     * A task is ready if it is Pending (not Blocked or other) and every UUID in
     * its depends_on array refers to a task that is Validated or Skipped.
     * Tasks with an empty depends_on are always ready when Pending.
     *
     * @param  Collection<int, CrewTaskExecution>  $tasks
     * @return Collection<int, CrewTaskExecution>
     */
    public function resolveReady(Collection $tasks): Collection
    {
        // Build a set of satisfied task UUIDs (Validated or Skipped)
        $satisfiedIds = $tasks
            ->filter(fn (CrewTaskExecution $t) => $t->isValidated() || $t->status === CrewTaskStatus::Skipped)
            ->pluck('id')
            ->flip() // O(1) lookup
            ->toArray();

        return $tasks
            ->filter(function (CrewTaskExecution $task) use ($satisfiedIds) {
                // Only Pending tasks can be dispatched; Blocked tasks wait for autoUnblock
                if (! $task->isPending()) {
                    return false;
                }

                $deps = $task->depends_on ?? [];
                if (empty($deps)) {
                    return true;
                }

                // All dependency UUIDs must be in the satisfied set
                foreach ($deps as $depId) {
                    if (! isset($satisfiedIds[(string) $depId])) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * Check if all tasks have reached a terminal state (validated, qa_failed, or skipped).
     */
    public function allTerminal(Collection $tasks): bool
    {
        return $tasks->every(fn (CrewTaskExecution $t) => $t->status->isTerminal());
    }

    /**
     * Check if there are any Pending or Blocked tasks that can never be unblocked
     * because at least one of their UUID dependencies reached a terminal failed state
     * (QaFailed or Failed).
     */
    public function hasDeadlock(Collection $tasks): bool
    {
        $failedIds = $tasks
            ->filter(fn (CrewTaskExecution $t) => $t->status === CrewTaskStatus::QaFailed || $t->status === CrewTaskStatus::Failed)
            ->pluck('id')
            ->flip() // O(1) lookup
            ->toArray();

        if (empty($failedIds)) {
            return false;
        }

        // Both Pending and Blocked tasks with a failed dependency are deadlocked
        return $tasks
            ->filter(fn (CrewTaskExecution $t) => $t->isPending() || $t->isBlocked())
            ->contains(function (CrewTaskExecution $task) use ($failedIds) {
                $deps = $task->depends_on ?? [];
                foreach ($deps as $depId) {
                    if (isset($failedIds[(string) $depId])) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Gather validated outputs from dependency tasks for input context.
     * Dependencies are stored as UUID strings after the second-pass remap in DecomposeGoalAction.
     *
     * @return array<string, mixed>
     */
    public function gatherDependencyOutputs(CrewTaskExecution $task, Collection $allTasks): array
    {
        $deps = $task->depends_on ?? [];
        if (empty($deps)) {
            return [];
        }

        // Index tasks by UUID for O(1) lookup
        $taskById = $allTasks->keyBy('id');

        $outputs = [];
        foreach ($deps as $depId) {
            $depTask = $taskById->get((string) $depId);
            if ($depTask && $depTask->output) {
                $outputs[$depTask->title] = $depTask->output;
            }
        }

        return $outputs;
    }
}
