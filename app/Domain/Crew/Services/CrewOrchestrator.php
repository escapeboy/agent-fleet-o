<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Actions\DecomposeGoalAction;
use App\Domain\Crew\Actions\SynthesizeResultAction;
use App\Domain\Crew\Actions\ValidateTaskOutputAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Jobs\CoordinatorDecisionJob;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Jobs\ValidateCrewTaskJob;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class CrewOrchestrator
{
    public function __construct(
        private readonly DecomposeGoalAction $decomposeGoal,
        private readonly SynthesizeResultAction $synthesizeResult,
        private readonly ValidateTaskOutputAction $validateTaskOutput,
        private readonly TaskDependencyResolver $dependencyResolver,
    ) {}

    /**
     * Run the full plan → execute → validate → synthesize lifecycle.
     */
    public function run(CrewExecution $execution): void
    {
        try {
            // Phase 1: Decompose goal
            $taskExecutions = $this->decomposeGoal->execute($execution);

            if (empty($taskExecutions)) {
                $this->failExecution($execution, 'Coordinator produced an empty task plan.');

                return;
            }

            // Phase 2: Transition to executing
            $execution->update(['status' => CrewExecutionStatus::Executing]);

            // Phase 3: Dispatch tasks based on process type
            $processType = CrewProcessType::from($execution->config_snapshot['process_type']);

            match ($processType) {
                CrewProcessType::Sequential => $this->dispatchSequential($execution),
                CrewProcessType::Parallel => $this->dispatchParallel($execution),
                CrewProcessType::Hierarchical => $this->dispatchHierarchical($execution),
            };
        } catch (\Throwable $e) {
            Log::error('Crew orchestration failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);

            $this->failExecution($execution, $e->getMessage());
        }
    }

    /**
     * Sequential: dispatch ready tasks one at a time.
     * Called initially and after each task validation.
     */
    public function dispatchSequential(CrewExecution $execution): void
    {
        $tasks = $execution->taskExecutions()->get();
        $ready = $this->dependencyResolver->resolveReady($tasks);

        if ($ready->isEmpty()) {
            if ($this->dependencyResolver->allTerminal($tasks)) {
                $this->synthesizeAndComplete($execution);
            } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
                $this->failExecution($execution, 'Deadlock: remaining tasks depend on failed tasks.');
            }
            // Otherwise tasks are still running, wait for callbacks

            return;
        }

        // Dispatch only the first ready task (sequential = one at a time)
        $nextTask = $ready->first();
        $this->dispatchTask($nextTask, $execution);
    }

    /**
     * Parallel: dispatch all independent tasks at once via Bus::batch.
     */
    public function dispatchParallel(CrewExecution $execution): void
    {
        $tasks = $execution->taskExecutions()->get();
        $ready = $this->dependencyResolver->resolveReady($tasks);

        if ($ready->isEmpty()) {
            if ($this->dependencyResolver->allTerminal($tasks)) {
                $this->synthesizeAndComplete($execution);
            } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
                $this->failExecution($execution, 'Deadlock: remaining tasks depend on failed tasks.');
            }

            return;
        }

        $jobs = $ready->map(fn (CrewTaskExecution $task) => new ExecuteCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $execution->team_id,
        ))->toArray();

        $batch = Bus::batch($jobs)
            ->name("crew:{$execution->id}")
            ->onQueue('ai-calls')
            ->allowFailures()
            ->dispatch();

        // Store batch_id on tasks
        $ready->each(fn (CrewTaskExecution $task) => $task->update(['batch_id' => $batch->id]));
    }

    /**
     * Hierarchical: coordinator decides one task at a time dynamically.
     */
    public function dispatchHierarchical(CrewExecution $execution): void
    {
        CoordinatorDecisionJob::dispatch(
            crewExecutionId: $execution->id,
            teamId: $execution->team_id,
        );
    }

    /**
     * Called after a task is validated — continue the flow.
     */
    public function onTaskValidated(CrewExecution $execution, CrewTaskExecution $task): void
    {
        $processType = CrewProcessType::from($execution->config_snapshot['process_type']);

        match ($processType) {
            CrewProcessType::Sequential => $this->dispatchSequential($execution),
            CrewProcessType::Parallel => $this->checkParallelProgress($execution),
            CrewProcessType::Hierarchical => $this->dispatchHierarchical($execution),
        };
    }

    /**
     * Called when QA rejects a task — retry or escalate.
     */
    public function onTaskRejected(CrewExecution $execution, CrewTaskExecution $task): void
    {
        if ($task->canRetry()) {
            // Retry: increment attempt, reset status, re-dispatch
            $task->update([
                'status' => CrewTaskStatus::Pending,
                'attempt_number' => $task->attempt_number + 1,
                'output' => null,
                'error_message' => null,
            ]);

            $this->dispatchTask($task, $execution);
        } else {
            // Max retries exhausted — mark as QA failed
            $task->update(['status' => CrewTaskStatus::QaFailed]);

            $processType = CrewProcessType::from($execution->config_snapshot['process_type']);

            if ($processType === CrewProcessType::Hierarchical) {
                // Let coordinator decide what to do
                $this->dispatchHierarchical($execution);
            } else {
                // Check if we can continue (sequential/parallel skip this task)
                $this->checkContinuation($execution);
            }
        }
    }

    /**
     * Called when a task execution fails (worker error).
     */
    public function onTaskFailed(CrewExecution $execution, CrewTaskExecution $task): void
    {
        if ($task->canRetry()) {
            $task->update([
                'status' => CrewTaskStatus::Pending,
                'attempt_number' => $task->attempt_number + 1,
                'output' => null,
            ]);

            $this->dispatchTask($task, $execution);
        } else {
            $task->update(['status' => CrewTaskStatus::Failed]);
            $this->checkContinuation($execution);
        }
    }

    private function dispatchTask(CrewTaskExecution $task, CrewExecution $execution): void
    {
        // Gather dependency outputs as context
        $allTasks = $execution->taskExecutions()->get();
        $depOutputs = $this->dependencyResolver->gatherDependencyOutputs($task, $allTasks);

        if (! empty($depOutputs)) {
            $context = $task->input_context ?? [];
            $context['dependency_outputs'] = $depOutputs;
            $task->update(['input_context' => $context]);
        }

        $task->update(['status' => CrewTaskStatus::Assigned]);

        ExecuteCrewTaskJob::dispatch(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $execution->team_id,
        );
    }

    private function checkParallelProgress(CrewExecution $execution): void
    {
        $tasks = $execution->taskExecutions()->get();

        // Check if there are more tasks to dispatch (phase 2 of dependency resolution)
        $ready = $this->dependencyResolver->resolveReady($tasks);

        if ($ready->isNotEmpty()) {
            $this->dispatchParallel($execution);
        } elseif ($this->dependencyResolver->allTerminal($tasks)) {
            $this->synthesizeAndComplete($execution);
        } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
            $this->failExecution($execution, 'Deadlock: remaining tasks depend on failed tasks.');
        }
        // Otherwise, still waiting on running tasks
    }

    private function checkContinuation(CrewExecution $execution): void
    {
        $tasks = $execution->taskExecutions()->get();

        if ($this->dependencyResolver->allTerminal($tasks)) {
            // Check if we have enough validated tasks to produce a result
            $validatedCount = $tasks->filter(fn ($t) => $t->isValidated())->count();

            if ($validatedCount > 0) {
                $this->synthesizeAndComplete($execution);
            } else {
                $this->failExecution($execution, 'All tasks failed — no validated outputs to synthesize.');
            }
        } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
            // Partial completion with whatever is validated
            $validatedCount = $tasks->filter(fn ($t) => $t->isValidated())->count();

            if ($validatedCount > 0) {
                $this->synthesizeAndComplete($execution);
            } else {
                $this->failExecution($execution, 'Deadlock with no validated outputs.');
            }
        } else {
            // Continue with remaining tasks
            $processType = CrewProcessType::from($execution->config_snapshot['process_type']);
            match ($processType) {
                CrewProcessType::Sequential => $this->dispatchSequential($execution),
                CrewProcessType::Parallel => $this->dispatchParallel($execution),
                CrewProcessType::Hierarchical => $this->dispatchHierarchical($execution),
            };
        }
    }

    public function synthesizeAndComplete(CrewExecution $execution): void
    {
        try {
            $result = $this->synthesizeResult->execute($execution);

            // Final QA validation on the synthesized result
            $execution->update([
                'final_output' => $result['result'],
                'total_cost_credits' => $execution->total_cost_credits + $result['cost'],
            ]);

            // Run final QA on the assembled result
            $finalQa = $this->runFinalQa($execution);

            $execution->update([
                'quality_score' => $finalQa['score'],
                'status' => CrewExecutionStatus::Completed,
                'completed_at' => now(),
                'duration_ms' => $execution->started_at
                    ? (int) $execution->started_at->diffInMilliseconds(now())
                    : null,
            ]);

            activity()
                ->performedOn($execution)
                ->withProperties([
                    'quality_score' => $finalQa['score'],
                    'total_cost_credits' => $execution->total_cost_credits,
                    'duration_ms' => $execution->duration_ms,
                ])
                ->log('crew.execution_completed');
        } catch (\Throwable $e) {
            $this->failExecution($execution, 'Synthesis failed: '.$e->getMessage());
        }
    }

    /**
     * @return array{passed: bool, score: float, feedback: string}
     */
    private function runFinalQa(CrewExecution $execution): array
    {
        // Create a virtual "final result" task for QA
        $virtualTask = new CrewTaskExecution([
            'title' => 'Final Result',
            'description' => "Assembled result for goal: {$execution->goal}",
            'output' => $execution->final_output,
            'input_context' => ['expected_output' => 'Complete, cohesive result matching the original goal'],
        ]);

        // We don't persist the virtual task — just use it for validation
        try {
            return $this->validateTaskOutput->execute($virtualTask, $execution);
        } catch (\Throwable $e) {
            // If final QA fails, still complete but with 0 score
            return [
                'passed' => false,
                'score' => 0.0,
                'feedback' => 'Final QA validation failed: '.$e->getMessage(),
                'issues' => [],
            ];
        }
    }

    private function failExecution(CrewExecution $execution, string $error): void
    {
        $execution->update([
            'status' => CrewExecutionStatus::Failed,
            'error_message' => $error,
            'completed_at' => now(),
            'duration_ms' => $execution->started_at
                ? (int) $execution->started_at->diffInMilliseconds(now())
                : null,
        ]);

        activity()
            ->performedOn($execution)
            ->withProperties(['error' => $error])
            ->log('crew.execution_failed');
    }
}
