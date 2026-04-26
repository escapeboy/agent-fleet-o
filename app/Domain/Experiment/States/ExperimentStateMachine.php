<?php

namespace App\Domain\Experiment\States;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\DeadlineContext;
use InvalidArgumentException;

class ExperimentStateMachine
{
    public function validate(Experiment $experiment, ExperimentStatus $toState): void
    {
        // Honor MCP-propagated deadline on synchronous transition paths
        // (e.g. experiment_kill / experiment_pause called via MCP tools).
        app(DeadlineContext::class)->assertNotExpired();

        $fromState = $experiment->status;

        if (! ExperimentTransitionMap::canTransition($fromState, $toState)) {
            throw new InvalidArgumentException(
                "Invalid transition from [{$fromState->value}] to [{$toState->value}] for experiment [{$experiment->id}].",
            );
        }

        // Enforce retry limits for failed → retry transitions
        if ($this->isRetryTransition($fromState, $toState)) {
            $this->enforceRetryLimit($experiment, $toState);
        }

        // Enforce iteration limit
        if ($toState === ExperimentStatus::Iterating) {
            $this->enforceIterationLimit($experiment);
        }

        // Enforce rejection cycle limit
        if ($fromState === ExperimentStatus::Rejected && $toState === ExperimentStatus::Planning) {
            $this->enforceRejectionCycleLimit($experiment);
        }
    }

    private function isRetryTransition(ExperimentStatus $from, ExperimentStatus $to): bool
    {
        return match (true) {
            $from === ExperimentStatus::ScoringFailed && $to === ExperimentStatus::Scoring => true,
            $from === ExperimentStatus::PlanningFailed && $to === ExperimentStatus::Planning => true,
            $from === ExperimentStatus::BuildingFailed && $to === ExperimentStatus::Building => true,
            $from === ExperimentStatus::ExecutionFailed && $to === ExperimentStatus::Executing => true,
            default => false,
        };
    }

    private function enforceRetryLimit(Experiment $experiment, ExperimentStatus $stage): void
    {
        $stageType = $this->statusToStageType($stage);
        $maxRetries = $experiment->constraints['max_retries_per_stage'] ?? 3;

        $currentRetries = $experiment->stages()
            ->where('stage', $stageType)
            ->where('iteration', $experiment->current_iteration)
            ->max('retry_count') ?? 0;

        if ($currentRetries >= $maxRetries) {
            throw new InvalidArgumentException(
                "Max retries ({$maxRetries}) exceeded for stage [{$stageType}] on experiment [{$experiment->id}].",
            );
        }
    }

    private function enforceIterationLimit(Experiment $experiment): void
    {
        $maxIterations = $experiment->max_iterations ?? 10;

        // RunEvaluationStage::handleIterate increments current_iteration BEFORE
        // calling transition->execute(toState: Iterating). So when current=N
        // here, the experiment has just bumped to its N-th iteration cycle and
        // is starting it — N == max_iterations is the LAST allowed cycle, not
        // a violation. Throw only when we're truly past max (N > max).
        if ($experiment->current_iteration > $maxIterations) {
            throw new InvalidArgumentException(
                "Max iterations ({$maxIterations}) exceeded for experiment [{$experiment->id}].",
            );
        }
    }

    private function enforceRejectionCycleLimit(Experiment $experiment): void
    {
        $maxCycles = $experiment->constraints['max_rejection_cycles'] ?? 3;

        $rejectionCount = $experiment->stateTransitions()
            ->where('to_state', ExperimentStatus::Rejected->value)
            ->where('from_state', ExperimentStatus::AwaitingApproval->value)
            ->count();

        if ($rejectionCount >= $maxCycles) {
            throw new InvalidArgumentException(
                "Max rejection cycles ({$maxCycles}) exceeded for experiment [{$experiment->id}].",
            );
        }
    }

    private function statusToStageType(ExperimentStatus $status): string
    {
        return match ($status) {
            ExperimentStatus::Scoring => 'scoring',
            ExperimentStatus::Planning => 'planning',
            ExperimentStatus::Building => 'building',
            ExperimentStatus::Executing => 'executing',
            default => $status->value,
        };
    }
}
