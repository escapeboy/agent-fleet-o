<?php

namespace App\Domain\Crew\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\BuildAdversarialRoundTasksAction;
use App\Domain\Crew\Actions\ClaimNextTaskAction;
use App\Domain\Crew\Actions\CollectCrewArtifactsAction;
use App\Domain\Crew\Actions\DecomposeGoalAction;
use App\Domain\Crew\Actions\SendAgentMessageAction;
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
use App\Mcp\DeadlineContext;
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
            // Honor MCP-propagated deadline if the orchestrator was invoked
            // synchronously via an MCP tool (e.g., crew_execute).
            app(DeadlineContext::class)->assertNotExpired();

            $processType = CrewProcessType::from($execution->config_snapshot['process_type']);

            // Fanout and ChatRoom manage their own task creation — skip decomposition
            if (in_array($processType, [CrewProcessType::Fanout, CrewProcessType::ChatRoom])) {
                $execution->update(['status' => CrewExecutionStatus::Executing]);
            } else {
                // Phase 1: Decompose goal
                $taskExecutions = $this->decomposeGoal->execute($execution);

                if (empty($taskExecutions)) {
                    $this->failExecution($execution, 'Coordinator produced an empty task plan.');

                    return;
                }

                // Phase 2: Transition to executing
                $execution->update(['status' => CrewExecutionStatus::Executing]);
            }

            match ($processType) {
                CrewProcessType::Sequential => $this->dispatchSequential($execution),
                CrewProcessType::Parallel => $this->dispatchParallel($execution),
                CrewProcessType::Hierarchical => $this->dispatchHierarchical($execution),
                CrewProcessType::SelfClaim => $this->dispatchSelfClaim($execution),
                CrewProcessType::Adversarial => $this->dispatchAdversarial($execution),
                CrewProcessType::Fanout => $this->dispatchFanout($execution),
                CrewProcessType::ChatRoom => $this->dispatchChatRoom($execution),
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
     * Self-Claim: seed one task per worker; agents claim subsequent tasks themselves after completion.
     */
    public function dispatchSelfClaim(CrewExecution $execution): void
    {
        $config = $execution->config_snapshot;
        $workers = collect($config['workers'] ?? []);
        $tasks = $execution->taskExecutions()->get();

        if ($tasks->isEmpty()) {
            $this->failExecution($execution, 'No tasks to execute.');

            return;
        }

        $pendingTasks = $tasks->where('status', CrewTaskStatus::Pending->value);

        // Seed: dispatch one task per worker (they self-claim after each completion)
        foreach ($workers->take($pendingTasks->count()) as $workerConfig) {
            $agent = Agent::withoutGlobalScopes()
                ->where('team_id', $execution->team_id)
                ->find($workerConfig['id']);
            if (! $agent) {
                continue;
            }

            $claimed = app(ClaimNextTaskAction::class)->execute($execution, $agent);
            if ($claimed) {
                ExecuteCrewTaskJob::dispatch(
                    crewExecutionId: $execution->id,
                    taskExecutionId: $claimed->id,
                    teamId: $execution->team_id,
                );
            }
        }

        // If no workers but tasks exist, seed with coordinator
        if ($workers->isEmpty() && $pendingTasks->isNotEmpty()) {
            $coordinator = Agent::withoutGlobalScopes()
                ->where('team_id', $execution->team_id)
                ->find($config['coordinator']['id']);
            if ($coordinator) {
                $claimed = app(ClaimNextTaskAction::class)->execute($execution, $coordinator);
                if ($claimed) {
                    ExecuteCrewTaskJob::dispatch(
                        crewExecutionId: $execution->id,
                        taskExecutionId: $claimed->id,
                        teamId: $execution->team_id,
                    );
                }
            }
        }
    }

    /**
     * Adversarial: round 1 tasks are already created by DecomposeGoalAction — dispatch them as parallel.
     */
    public function dispatchAdversarial(CrewExecution $execution): void
    {
        // Round 1 hypothesis tasks are created as Pending by DecomposeGoalAction; dispatch them in parallel
        $this->dispatchParallel($execution);
    }

    /**
     * Fanout: broadcast the same input to ALL crew members simultaneously.
     *
     * Skips goal decomposition — each agent receives the crew's original goal
     * and works on it independently. Results are gathered and synthesized.
     * Ideal for getting diverse perspectives on the same problem.
     */
    public function dispatchFanout(CrewExecution $execution): void
    {
        $crew = $execution->crew;
        $members = $crew->workerMembers()->with('agent')->get();

        if ($members->isEmpty()) {
            $this->failExecution($execution, 'No worker members in crew for fanout.');

            return;
        }

        // Create one task per member, all with the same goal as input.
        // External members get external_agent_id set (and agent_id left null); the
        // orchestrator branches on that in dispatchTask().
        $taskExecutions = [];
        foreach ($members as $index => $member) {
            $memberName = $member->isExternal()
                ? (string) $member->externalAgent?->name
                : (string) $member->agent?->name;

            $taskExecutions[] = CrewTaskExecution::create([
                'crew_execution_id' => $execution->id,
                'agent_id' => $member->isExternal() ? null : $member->agent_id,
                'external_agent_id' => $member->isExternal() ? $member->external_agent_id : null,
                'title' => "Fanout: {$memberName}",
                'description' => $execution->goal,
                'status' => CrewTaskStatus::Pending,
                'input_context' => [
                    'fanout_mode' => true,
                    'original_goal' => $execution->goal,
                    'expected_output' => 'Provide your independent analysis or solution.',
                    'assigned_to' => $memberName,
                    'external' => $member->isExternal(),
                ],
                'depends_on' => [],
                'attempt_number' => 1,
                'max_attempts' => $execution->config_snapshot['max_task_iterations'] ?? 3,
                'sort_order' => $index,
            ]);
        }

        $execution->update([
            'task_plan' => collect($taskExecutions)->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'agent' => $t->isExternal()
                    ? $t->externalAgent?->name
                    : $t->agent?->name,
            ])->toArray(),
        ]);

        // Dispatch all in parallel. Route external tasks through the protocol dispatcher
        // instead of ExecuteCrewTaskJob (which assumes internal agent skill resolution).
        $internalTasks = collect($taskExecutions)->filter(fn (CrewTaskExecution $t) => ! $t->isExternal());
        $externalTasks = collect($taskExecutions)->filter(fn (CrewTaskExecution $t) => $t->isExternal());

        foreach ($externalTasks as $task) {
            app(CrewExternalMemberDispatcher::class)->dispatch($task, $execution);
        }

        $jobs = $internalTasks->map(fn (CrewTaskExecution $task) => new ExecuteCrewTaskJob(
            crewExecutionId: $execution->id,
            taskExecutionId: $task->id,
            teamId: $execution->team_id,
        ))->toArray();

        if (! empty($jobs)) {
            Bus::batch($jobs)
                ->name("crew-fanout:{$execution->id}")
                ->onQueue('ai-calls')
                ->dispatch();
        }
    }

    /**
     * ChatRoom: agents discuss collaboratively in rounds on a shared message bus.
     */
    public function dispatchChatRoom(CrewExecution $execution): void
    {
        $orchestrator = app(CrewChatRoomOrchestrator::class);
        $orchestrator->start($execution);
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
            CrewProcessType::SelfClaim => $this->continueSelfClaim($execution, $task),
            CrewProcessType::Adversarial => $this->advanceAdversarialRound($execution),
            CrewProcessType::Fanout => $this->checkParallelProgress($execution),
            CrewProcessType::ChatRoom => null, // ChatRoom handles its own flow
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
                // Check if we can continue (sequential/parallel/self_claim/adversarial skip this task)
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

    /**
     * Continue a Self-Claim execution after a task completes:
     * the same agent claims the next available task, or we check for completion.
     */
    private function continueSelfClaim(CrewExecution $execution, CrewTaskExecution $completedTask): void
    {
        // The agent who just finished claims the next task
        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $execution->team_id)
            ->find($completedTask->agent_id);

        if ($agent) {
            $nextTask = app(ClaimNextTaskAction::class)->execute($execution, $agent);
            if ($nextTask) {
                ExecuteCrewTaskJob::dispatch(
                    crewExecutionId: $execution->id,
                    taskExecutionId: $nextTask->id,
                    teamId: $execution->team_id,
                );

                return;
            }
        }

        // No more tasks available for this agent — check if all agents are done
        $tasks = $execution->taskExecutions()->get();
        if ($this->dependencyResolver->allTerminal($tasks)) {
            if ($this->checkConvergenceReached($execution)) {
                $this->synthesizeAndComplete($execution);
            } else {
                $this->failExecution($execution, 'Convergence not met.');
            }
        }
        // Otherwise other agents are still working; they will trigger continueSelfClaim when done
    }

    /**
     * Advance an adversarial execution after a task validates:
     * store findings as messages, then either start the next debate round or synthesize.
     */
    private function advanceAdversarialRound(CrewExecution $execution): void
    {
        $config = $execution->config_snapshot;
        $maxRounds = $config['adversarial_rounds'] ?? 2;

        $tasks = $execution->taskExecutions()->get();

        // Determine the current round from task input_context
        $currentRound = $tasks->max(fn ($t) => $t->input_context['debate_round'] ?? 1) ?? 1;

        // Collect tasks for the current round
        $roundTasks = $tasks->filter(fn ($t) => ($t->input_context['debate_round'] ?? 1) === $currentRound);

        // Wait until all tasks in the current round are terminal before advancing
        $allRoundDone = $roundTasks->every(fn ($t) => $t->status->isTerminal());
        if (! $allRoundDone) {
            return;
        }

        // Store each validated task's output as a "finding" message for the next round to reference
        $completedRoundTasks = $roundTasks->filter(fn ($t) => $t->isValidated());
        foreach ($completedRoundTasks as $task) {
            if ($task->output) {
                app(SendAgentMessageAction::class)->execute(
                    execution: $execution,
                    messageType: 'finding',
                    content: json_encode($task->output, JSON_UNESCAPED_UNICODE),
                    sender: Agent::withoutGlobalScopes()
                        ->where('team_id', $execution->team_id)
                        ->find($task->agent_id),
                    round: $currentRound,
                );
            }
        }

        // Advance to next round or synthesize
        if ($currentRound < $maxRounds) {
            $nextRoundTasks = app(BuildAdversarialRoundTasksAction::class)->execute(
                $execution,
                $currentRound + 1,
                $roundTasks->values()->all(),
            );

            // Dispatch the new round's tasks directly (they are created as Pending)
            foreach ($nextRoundTasks as $newTask) {
                ExecuteCrewTaskJob::dispatch(
                    crewExecutionId: $execution->id,
                    taskExecutionId: $newTask->id,
                    teamId: $execution->team_id,
                );
            }
        } else {
            // All rounds complete — synthesize into a final conclusion
            if ($this->checkConvergenceReached($execution)) {
                $this->synthesizeAndComplete($execution);
            } else {
                $this->failExecution($execution, 'Adversarial convergence not met.');
            }
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

            if ($currentTask->external_agent_id !== null) {
                // Route external members through the Agent Chat Protocol (synchronous dispatch;
                // the protocol's own retries + circuit breaker handle reliability).
                app(CrewExternalMemberDispatcher::class)
                    ->dispatch($currentTask, $execution);

                return;
            }

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
                // For self-claim, re-seed available workers
                CrewProcessType::SelfClaim => $this->dispatchSelfClaim($execution),
                // For adversarial, re-dispatch the current round
                CrewProcessType::Adversarial => $this->dispatchAdversarial($execution),
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

    /**
     * Filter shared context down to only the keys permitted for a given crew member.
     *
     * When context_scope is null (or empty) the member receives the full context — this
     * preserves backwards-compatible behaviour for all existing crews.
     *
     * @param  array<string, mixed>  $context  Full shared execution context
     * @param  string[]|null  $contextScope  Allowlisted top-level context keys, or null for unrestricted
     * @return array<string, mixed>
     */
    public function filterContextForMember(array $context, ?array $contextScope): array
    {
        if (empty($contextScope)) {
            return $context; // null = full context (backwards compatible)
        }

        return array_intersect_key($context, array_flip($contextScope));
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
