<?php

namespace App\Domain\Experiment\States;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;

class TransitionPrerequisiteValidator
{
    /**
     * Validate that prerequisites are met for a state transition.
     * Returns null if valid, or an error message if invalid.
     */
    public function validate(Experiment $experiment, ExperimentStatus $toState): ?string
    {
        return match ($toState) {
            ExperimentStatus::Building => $this->validateBuildingPrereqs($experiment),
            ExperimentStatus::Executing => $this->validateExecutingPrereqs($experiment),
            ExperimentStatus::CollectingMetrics => $this->validateMetricsPrereqs($experiment),
            default => null,
        };
    }

    private function validateBuildingPrereqs(Experiment $experiment): ?string
    {
        $planStage = $experiment->stages()
            ->where('stage', StageType::Planning)
            ->where('status', StageStatus::Completed)
            ->whereNotNull('output_snapshot')
            ->latest()
            ->first();

        if (! $planStage || empty($planStage->output_snapshot)) {
            return 'Cannot transition to Building: no completed plan exists.';
        }

        return null;
    }

    private function validateExecutingPrereqs(Experiment $experiment): ?string
    {
        // Workflow experiments coming from Draft (project runs) skip planning.
        // They need materialized playbook steps.
        $hasWorkflow = ! empty($experiment->constraints['workflow_graph']);

        if ($hasWorkflow && ! $experiment->playbookSteps()->exists()) {
            return 'Cannot transition to Executing: no playbook steps materialized from workflow.';
        }

        return null;
    }

    private function validateMetricsPrereqs(Experiment $experiment): ?string
    {
        // If the experiment has playbook steps, at least one should be completed
        if ($experiment->playbookSteps()->exists()) {
            $completed = $experiment->playbookSteps()->where('status', 'completed')->count();

            if ($completed === 0) {
                return 'Cannot transition to CollectingMetrics: no playbook steps completed.';
            }
        }

        return null;
    }
}
