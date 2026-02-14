<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExecutionMode;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Project\Enums\ProjectType;
use App\Domain\Project\Models\ProjectRun;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class PlaybookExecutor
{
    public function __construct(
        private readonly TransitionExperimentAction $transitionAction,
        private readonly LocalAgentDiscovery $discovery,
    ) {}

    /**
     * Execute all playbook steps for an experiment.
     * Groups steps by group_id for parallel execution.
     * Sequential steps are dispatched one at a time via batch callbacks.
     */
    public function execute(Experiment $experiment): void
    {
        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get();

        if ($steps->isEmpty()) {
            Log::warning('PlaybookExecutor: No steps found', ['experiment_id' => $experiment->id]);
            [$state, $msg] = self::resolveCompletionState($experiment, 'Playbook completed (no steps)');
            $this->transitionAction->execute($experiment, $state, $msg);

            return;
        }

        // Group steps: sequential steps get their own group, parallel steps share a group_id
        $groups = $this->buildGroups($steps);

        // Convert groups to serializable form (step IDs only) for batch callbacks
        $groupStepIds = array_map(
            fn (array $group) => array_map(fn (PlaybookStep $step) => $step->id, $group),
            $groups,
        );

        // Dispatch the first group, each group's completion triggers the next
        $this->dispatchGroup($experiment, $groupStepIds, 0);
    }

    /**
     * Build ordered groups of steps.
     * Steps with the same group_id are in one group (parallel).
     * Steps without group_id get individual groups (sequential).
     *
     * When the host bridge is active (single-threaded PHP server), all steps
     * are forced into sequential single-step groups because the bridge can
     * only process one agent request at a time. Parallel HTTP requests would
     * queue at the TCP level and later requests would time out waiting.
     */
    private function buildGroups(Collection $steps): array
    {
        // Single-threaded bridge cannot handle concurrent requests — serialize everything
        if ($this->discovery->isBridgeMode()) {
            return $steps->map(fn (PlaybookStep $step) => [$step])->values()->all();
        }

        $groups = [];
        $currentGroup = [];
        $lastGroupId = null;

        foreach ($steps as $step) {
            if ($step->execution_mode === ExecutionMode::Parallel && $step->group_id) {
                if ($lastGroupId !== $step->group_id && ! empty($currentGroup)) {
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                }
                $currentGroup[] = $step;
                $lastGroupId = $step->group_id;
            } else {
                if (! empty($currentGroup)) {
                    $groups[] = $currentGroup;
                    $currentGroup = [];
                }
                $groups[] = [$step];
                $lastGroupId = null;
            }
        }

        if (! empty($currentGroup)) {
            $groups[] = $currentGroup;
        }

        return $groups;
    }

    /**
     * Dispatch a group of steps.
     * If the group has multiple steps, use Bus::batch() for parallel execution.
     * Otherwise, dispatch a single job.
     *
     * IMPORTANT: Batch callbacks are serialized into the database. They must NOT
     * capture `$this` (PlaybookExecutor) because its injected dependencies cannot
     * be reliably deserialized by laravel/serializable-closure. Instead, callbacks
     * resolve a fresh PlaybookExecutor from the container via static helper methods.
     */
    private function dispatchGroup(Experiment $experiment, array $groupStepIds, int $groupIndex): void
    {
        if ($groupIndex >= count($groupStepIds)) {
            // All groups done — transition to CollectingMetrics
            self::transitionToCompleted($experiment->id);

            return;
        }

        $stepIds = $groupStepIds[$groupIndex];

        // Check for conditional steps that should be skipped
        $stepIds = array_values(array_filter($stepIds, function (string $stepId) use ($experiment) {
            $step = PlaybookStep::find($stepId);

            if ($step && $step->execution_mode === ExecutionMode::Conditional) {
                return $this->evaluateCondition($step, $experiment);
            }

            return true;
        }));

        if (empty($stepIds)) {
            // Skip this group, move to next
            $this->dispatchGroup($experiment, $groupStepIds, $groupIndex + 1);

            return;
        }

        $experimentId = $experiment->id;
        $teamId = $experiment->team_id;

        $jobs = array_map(
            fn (string $stepId) => new ExecutePlaybookStepJob($stepId, $experimentId, $teamId),
            $stepIds,
        );

        if (count($jobs) === 1) {
            // Single step — dispatch with completion callback
            Bus::batch($jobs)
                ->name("playbook:{$experimentId}:group:{$groupIndex}")
                ->onQueue('ai-calls')
                ->then(function () use ($experimentId, $groupStepIds, $groupIndex) {
                    self::onGroupCompleted($experimentId, $groupStepIds, $groupIndex);
                })
                ->catch(function () use ($experimentId) {
                    self::onGroupFailed($experimentId);
                })
                ->dispatch();
        } else {
            // Multiple steps — parallel batch
            Bus::batch($jobs)
                ->name("playbook:{$experimentId}:group:{$groupIndex}")
                ->onQueue('ai-calls')
                ->allowFailures()
                ->then(function () use ($experimentId, $groupStepIds, $groupIndex) {
                    // Check if any step in this group actually failed
                    $anyFailed = PlaybookStep::whereIn('id', $groupStepIds[$groupIndex])
                        ->where('status', 'failed')
                        ->exists();

                    if ($anyFailed) {
                        self::onGroupFailed($experimentId);
                    } else {
                        self::onGroupCompleted($experimentId, $groupStepIds, $groupIndex);
                    }
                })
                ->catch(function () use ($experimentId) {
                    // With allowFailures(), catch fires on first failure but batch continues.
                    // The then() callback handles the final decision.
                    Log::warning('PlaybookExecutor: step failure in parallel group', [
                        'experiment_id' => $experimentId,
                    ]);
                })
                ->dispatch();
        }
    }

    /**
     * Static callback: dispatch next group after current group completes.
     * Resolved from container to avoid serializing DI dependencies in closures.
     */
    private static function onGroupCompleted(string $experimentId, array $groupStepIds, int $completedGroupIndex): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experimentId);

            if (! $experiment || $experiment->status->isTerminal()) {
                return;
            }

            $executor = app(self::class);
            $executor->dispatchGroup($experiment, $groupStepIds, $completedGroupIndex + 1);
        } catch (\Throwable $e) {
            Log::error('PlaybookExecutor: onGroupCompleted failed', [
                'experiment_id' => $experimentId,
                'group_index' => $completedGroupIndex,
                'error' => $e->getMessage(),
            ]);

            self::onGroupFailed($experimentId);
        }
    }

    /**
     * Static callback: handle group failure by transitioning experiment.
     */
    private static function onGroupFailed(string $experimentId): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experimentId);

            if ($experiment && ! $experiment->status->isTerminal()) {
                app(TransitionExperimentAction::class)->execute(
                    $experiment,
                    ExperimentStatus::ExecutionFailed,
                    'Playbook step failed',
                );
            }
        } catch (\Throwable $e) {
            Log::error('PlaybookExecutor: onGroupFailed failed', [
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Static callback: transition experiment when all groups done.
     * One-shot projects complete directly; others go to CollectingMetrics.
     */
    private static function transitionToCompleted(string $experimentId): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experimentId);

            if (! $experiment || $experiment->status->isTerminal()) {
                return;
            }

            [$state, $msg] = self::resolveCompletionState($experiment, 'Playbook completed successfully');
            app(TransitionExperimentAction::class)->execute($experiment, $state, $msg);
        } catch (\Throwable $e) {
            Log::error('PlaybookExecutor: Failed to transition after playbook completion', [
                'experiment_id' => $experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Determine the completion state for an experiment.
     * One-shot projects complete directly; others go through evaluation.
     *
     * @return array{ExperimentStatus, string}
     */
    private static function resolveCompletionState(Experiment $experiment, string $reason): array
    {
        $projectRun = ProjectRun::where('experiment_id', $experiment->id)->first();
        $project = $projectRun?->project;

        if ($project && $project->type === ProjectType::OneShot) {
            return [ExperimentStatus::Completed, $reason.' (one-shot project)'];
        }

        return [ExperimentStatus::CollectingMetrics, $reason];
    }

    private function evaluateCondition(PlaybookStep $step, Experiment $experiment): bool
    {
        $conditions = $step->conditions;

        if (empty($conditions) || ! isset($conditions['if'])) {
            return true;
        }

        // Simple condition evaluation: "steps.{order}.output.{field} > {value}"
        $condition = $conditions['if'];

        if (preg_match('/^steps\.(\d+)\.output\.(\w+)\s*(>|<|>=|<=|==|!=)\s*(.+)$/', $condition, $matches)) {
            $stepOrder = (int) $matches[1];
            $field = $matches[2];
            $operator = $matches[3];
            $value = trim($matches[4]);

            $previousStep = PlaybookStep::where('experiment_id', $experiment->id)
                ->where('order', $stepOrder)
                ->first();

            if (! $previousStep || ! is_array($previousStep->output)) {
                return ! ($conditions['else_skip'] ?? false);
            }

            $actual = data_get($previousStep->output, $field);

            if ($actual === null) {
                return ! ($conditions['else_skip'] ?? false);
            }

            $numericValue = is_numeric($value) ? (float) $value : $value;

            return match ($operator) {
                '>' => $actual > $numericValue,
                '<' => $actual < $numericValue,
                '>=' => $actual >= $numericValue,
                '<=' => $actual <= $numericValue,
                '==' => $actual == $numericValue,
                '!=' => $actual != $numericValue,
                default => true,
            };
        }

        return true;
    }
}
