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
     * @param  Collection<int, CrewTaskExecution>  $tasks
     * @return Collection<int, CrewTaskExecution>
     */
    public function resolveReady(Collection $tasks): Collection
    {
        $validatedIndices = $tasks
            ->filter(fn (CrewTaskExecution $t) => $t->isValidated())
            ->pluck('sort_order')
            ->toArray();

        return $tasks
            ->filter(function (CrewTaskExecution $task) use ($validatedIndices) {
                if (! $task->isPending()) {
                    return false;
                }

                $deps = $task->depends_on ?? [];
                if (empty($deps)) {
                    return true;
                }

                // All dependency indices must be in the validated set
                foreach ($deps as $depIndex) {
                    if (! in_array((int) $depIndex, $validatedIndices, true)) {
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
     * Check if there are any blocked tasks that can never be unblocked
     * (their dependencies are in a terminal failed state).
     */
    public function hasDeadlock(Collection $tasks): bool
    {
        $failedIndices = $tasks
            ->filter(fn (CrewTaskExecution $t) => $t->status === CrewTaskStatus::QaFailed || $t->status === CrewTaskStatus::Skipped)
            ->pluck('sort_order')
            ->toArray();

        if (empty($failedIndices)) {
            return false;
        }

        return $tasks
            ->filter(fn (CrewTaskExecution $t) => $t->isPending())
            ->contains(function (CrewTaskExecution $task) use ($failedIndices) {
                $deps = $task->depends_on ?? [];
                foreach ($deps as $depIndex) {
                    if (in_array((int) $depIndex, $failedIndices, true)) {
                        return true;
                    }
                }

                return false;
            });
    }

    /**
     * Gather validated outputs from dependency tasks for input context.
     *
     * @return array<string, mixed>
     */
    public function gatherDependencyOutputs(CrewTaskExecution $task, Collection $allTasks): array
    {
        $deps = $task->depends_on ?? [];
        if (empty($deps)) {
            return [];
        }

        $outputs = [];
        foreach ($deps as $depIndex) {
            $depTask = $allTasks->firstWhere('sort_order', (int) $depIndex);
            if ($depTask && $depTask->output) {
                $outputs[$depTask->title] = $depTask->output;
            }
        }

        return $outputs;
    }
}
