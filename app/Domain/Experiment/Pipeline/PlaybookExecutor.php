<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExecutionMode;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class PlaybookExecutor
{
    public function __construct(
        private readonly TransitionExperimentAction $transitionAction,
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
            $this->transitionAction->execute($experiment, ExperimentStatus::CollectingMetrics, 'Playbook completed (no steps)');

            return;
        }

        // Group steps: sequential steps get their own group, parallel steps share a group_id
        $groups = $this->buildGroups($steps);

        // Dispatch the first group, each group's completion triggers the next
        $this->dispatchGroup($experiment, $groups, 0);
    }

    /**
     * Build ordered groups of steps.
     * Steps with the same group_id are in one group (parallel).
     * Steps without group_id get individual groups (sequential).
     */
    private function buildGroups(Collection $steps): array
    {
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
     */
    private function dispatchGroup(Experiment $experiment, array $groups, int $groupIndex): void
    {
        if ($groupIndex >= count($groups)) {
            // All groups done — transition to CollectingMetrics
            try {
                $this->transitionAction->execute(
                    $experiment,
                    ExperimentStatus::CollectingMetrics,
                    'Playbook completed successfully',
                );
            } catch (\Throwable $e) {
                Log::error('PlaybookExecutor: Failed to transition after playbook completion', [
                    'experiment_id' => $experiment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        $group = $groups[$groupIndex];

        // Check for conditional steps that should be skipped
        $group = array_filter($group, function (PlaybookStep $step) use ($experiment) {
            if ($step->execution_mode === ExecutionMode::Conditional) {
                return $this->evaluateCondition($step, $experiment);
            }

            return true;
        });

        if (empty($group)) {
            // Skip this group, move to next
            $this->dispatchGroup($experiment, $groups, $groupIndex + 1);

            return;
        }

        $jobs = array_map(
            fn (PlaybookStep $step) => new ExecutePlaybookStepJob($step->id, $experiment->id, $experiment->team_id),
            array_values($group),
        );

        if (count($jobs) === 1) {
            // Single step — dispatch directly with completion callback
            Bus::batch($jobs)
                ->name("playbook:{$experiment->id}:group:{$groupIndex}")
                ->onQueue('experiments')
                ->then(function () use ($experiment, $groups, $groupIndex) {
                    $this->dispatchGroup($experiment, $groups, $groupIndex + 1);
                })
                ->catch(function () use ($experiment) {
                    $this->handleGroupFailure($experiment);
                })
                ->dispatch();
        } else {
            // Multiple steps — parallel batch
            Bus::batch($jobs)
                ->name("playbook:{$experiment->id}:group:{$groupIndex}")
                ->onQueue('experiments')
                ->allowFailures()
                ->then(function () use ($experiment, $groups, $groupIndex) {
                    // Check if all steps in group succeeded
                    $groupStepIds = array_map(fn ($j) => $j->stepId, array_values(
                        array_filter($groups[$groupIndex], fn ($s) => $s instanceof PlaybookStep),
                    ));

                    // Re-check from jobs instead
                    $this->dispatchGroup($experiment, $groups, $groupIndex + 1);
                })
                ->catch(function () use ($experiment) {
                    $this->handleGroupFailure($experiment);
                })
                ->dispatch();
        }
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

    private function handleGroupFailure(Experiment $experiment): void
    {
        try {
            $experiment = Experiment::withoutGlobalScopes()->find($experiment->id);

            if ($experiment && ! $experiment->status->isTerminal()) {
                $this->transitionAction->execute(
                    $experiment,
                    ExperimentStatus::ExecutionFailed,
                    'Playbook step failed',
                );
            }
        } catch (\Throwable $e) {
            Log::error('PlaybookExecutor: Failed to handle group failure', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
