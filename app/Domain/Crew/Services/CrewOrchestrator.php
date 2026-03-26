<?php

namespace App\Domain\Crew\Services;

use App\Domain\Crew\Actions\CollectCrewArtifactsAction;
use App\Domain\Crew\Actions\DecomposeGoalAction;
use App\Domain\Crew\Actions\SynthesizeResultAction;
use App\Domain\Crew\Actions\ValidateTaskOutputAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Events\CrewExecuted;
use App\Domain\Crew\Jobs\CoordinatorDecisionJob;
use App\Domain\Crew\Jobs\ExecuteCrewTaskJob;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Shared\Services\NotificationService;
use App\Domain\Workflow\Services\ConditionEvaluator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class CrewOrchestrator
{
    public function __construct(
        private readonly DecomposeGoalAction $decomposeGoal,
        private readonly SynthesizeResultAction $synthesizeResult,
        private readonly ValidateTaskOutputAction $validateTaskOutput,
        private readonly TaskDependencyResolver $dependencyResolver,
        private readonly DependencyGraph $dependencyGraph,
        private readonly CollectCrewArtifactsAction $collectArtifacts,
        private readonly NotificationService $notifications,
        private readonly ConditionEvaluator $conditionEvaluator,
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
                if ($this->checkConvergenceReached($execution)) {
                    $this->synthesizeAndComplete($execution);
                } else {
                    $this->failExecution($execution, 'Convergence condition not met — insufficient validated tasks.');
                }
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
                if ($this->checkConvergenceReached($execution)) {
                    $this->synthesizeAndComplete($execution);
                } else {
                    $this->failExecution($execution, 'Convergence condition not met — insufficient validated tasks.');
                }
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
     * Called after a task is validated — unblock any dependent tasks then continue the flow.
     */
    public function onTaskValidated(CrewExecution $execution, CrewTaskExecution $task): void
    {
        // Unblock tasks whose UUID-based depends_on list is now fully satisfied.
        // autoUnblock() checks that $task->status is Validated or Skipped before acting,
        // and directly dispatches ExecuteCrewTaskJob for each newly unblocked task.
        $this->dependencyGraph->autoUnblock($execution, $task);

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
        // Iterative skip loop — avoids infinite recursion when consecutive tasks are skipped.
        // Maximum skip iterations prevents runaway loops from malformed skip_conditions.
        $maxSkipIterations = 50;
        $currentTask = $task;

        for ($i = 0; $i < $maxSkipIterations; $i++) {
            // Gather dependency outputs as context
            $allTasks = $execution->taskExecutions()->get();
            $depOutputs = $this->dependencyResolver->gatherDependencyOutputs($currentTask, $allTasks);

            if (! empty($depOutputs)) {
                $context = $currentTask->input_context ?? [];
                $context['dependency_outputs'] = $depOutputs;
                $currentTask->update(['input_context' => $context]);
            }

            // Evaluate skip_condition before dispatching
            if ($this->shouldSkipTask($currentTask, $allTasks)) {
                $currentTask->update([
                    'status' => CrewTaskStatus::Skipped,
                    'completed_at' => now(),
                ]);

                Log::info('Crew task skipped by condition', [
                    'task_id' => $currentTask->id,
                    'title' => $currentTask->title,
                    'skip_condition' => $currentTask->skip_condition,
                ]);

                // Find next ready task instead of recursing
                $tasks = $execution->taskExecutions()->get();
                $ready = $this->dependencyResolver->resolveReady($tasks);

                if ($ready->isEmpty()) {
                    if ($this->dependencyResolver->allTerminal($tasks)) {
                        if ($this->checkConvergenceReached($execution)) {
                            $this->synthesizeAndComplete($execution);
                        } else {
                            $this->failExecution($execution, 'Convergence condition not met — insufficient validated tasks.');
                        }
                    } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
                        $this->failExecution($execution, 'Deadlock: remaining tasks depend on failed tasks.');
                    }

                    return;
                }

                $currentTask = $ready->first();

                continue;
            }

            // Task is not skipped — dispatch it
            $currentTask->update(['status' => CrewTaskStatus::Assigned]);

            ExecuteCrewTaskJob::dispatch(
                crewExecutionId: $execution->id,
                taskExecutionId: $currentTask->id,
                teamId: $execution->team_id,
            );

            return;
        }

        // Safety: too many consecutive skips
        $this->failExecution($execution, 'Too many consecutive task skips — possible skip_condition loop.');
    }

    /**
     * Evaluate whether a task should be skipped based on its skip_condition.
     * Context is built from dependency task outputs keyed by sort_order.
     */
    private function shouldSkipTask(CrewTaskExecution $task, Collection $allTasks): bool
    {
        $condition = $task->skip_condition;

        if (empty($condition)) {
            return false;
        }

        // Build context from all completed/validated task outputs, keyed by sort_order.
        // Skipped tasks without output get an empty array so conditions like is_null can reason about them.
        $context = [];
        foreach ($allTasks as $t) {
            if ($t->isValidated() || $t->status === CrewTaskStatus::Skipped) {
                $context[(string) $t->sort_order] = $t->output ?? [];
            }
        }

        // Find the last dependency as the predecessor
        $deps = $task->depends_on ?? [];
        $predecessorId = ! empty($deps) ? (string) end($deps) : null;

        return $this->conditionEvaluator->evaluate($condition, $context, $predecessorId);
    }

    private function checkParallelProgress(CrewExecution $execution): void
    {
        $tasks = $execution->taskExecutions()->get();

        // Check if there are more tasks to dispatch (phase 2 of dependency resolution)
        $ready = $this->dependencyResolver->resolveReady($tasks);

        if ($ready->isNotEmpty()) {
            $this->dispatchParallel($execution);
        } elseif ($this->dependencyResolver->allTerminal($tasks)) {
            if ($this->checkConvergenceReached($execution)) {
                $this->synthesizeAndComplete($execution);
            } else {
                $this->failExecution($execution, 'Convergence condition not met — insufficient validated tasks.');
            }
        } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
            $this->failExecution($execution, 'Deadlock: remaining tasks depend on failed tasks.');
        }
        // Otherwise, still waiting on running tasks
    }

    private function checkContinuation(CrewExecution $execution): void
    {
        $tasks = $execution->taskExecutions()->get();

        if ($this->dependencyResolver->allTerminal($tasks)) {
            if ($this->checkConvergenceReached($execution)) {
                $this->synthesizeAndComplete($execution);
            } else {
                $this->failExecution($execution, 'Convergence condition not met — insufficient validated tasks.');
            }
        } elseif ($this->dependencyResolver->hasDeadlock($tasks)) {
            if ($this->checkConvergenceReached($execution)) {
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

            // Collect artifacts from task outputs
            $this->collectArtifacts->execute($execution);

            // Plugin hook: notify plugins of completed crew execution
            event(new CrewExecuted($execution->crew, $execution));

            activity()
                ->performedOn($execution)
                ->withProperties([
                    'quality_score' => $finalQa['score'],
                    'total_cost_credits' => $execution->total_cost_credits,
                    'duration_ms' => $execution->duration_ms,
                ])
                ->log('crew.execution_completed');

            if ($execution->team_id) {
                $this->notifications->notifyTeam(
                    teamId: $execution->team_id,
                    type: 'crew.execution.completed',
                    title: 'Crew Execution Complete',
                    body: sprintf(
                        'Crew "%s" finished successfully (quality score: %s%%).',
                        $execution->crew?->name ?? 'Crew',
                        round($finalQa['score'] * 100),
                    ),
                    actionUrl: '/crews/'.$execution->crew_id.'/execute',
                    data: ['crew_execution_id' => $execution->id, 'url' => '/crews/'.$execution->crew_id.'/execute'],
                );
            }
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

    /**
     * Check if the crew's convergence condition has been met for the given execution.
     */
    private function checkConvergenceReached(CrewExecution $execution): bool
    {
        $crew = $execution->crew;
        $tasks = $execution->taskExecutions;
        $total = $tasks->count();
        $validated = $tasks->where('status', CrewTaskStatus::Validated->value)->count();

        return match ($crew->convergence_mode) {
            'all_validated' => $validated === $total && $total > 0,
            'threshold_ratio' => $total > 0 && ($validated / $total) >= $crew->min_validated_ratio,
            // quality_gate defers to post-synthesis QA score evaluated inside synthesizeAndComplete(); allow
            // synthesis to proceed whenever at least one task is validated so the QA stage can run.
            'quality_gate' => $validated > 0,
            default => $validated > 0, // any_validated
        };
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
