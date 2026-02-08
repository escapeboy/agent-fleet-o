<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;

class CreateExperimentAction
{
    public function execute(
        string $userId,
        string $title,
        string $thesis,
        string $track,
        int $budgetCapCredits = 10000,
        int $maxIterations = 10,
        int $maxOutboundCount = 100,
        array $constraints = [],
        array $successCriteria = [],
        ?string $teamId = null,
    ): Experiment {
        return Experiment::create([
            'user_id' => $userId,
            'title' => $title,
            'thesis' => $thesis,
            'track' => $track,
            'status' => ExperimentStatus::Draft,
            'constraints' => array_merge([
                'max_retries_per_stage' => 3,
                'max_rejection_cycles' => 3,
            ], $constraints),
            'success_criteria' => $successCriteria,
            'budget_cap_credits' => $budgetCapCredits,
            'budget_spent_credits' => 0,
            'max_iterations' => $maxIterations,
            'current_iteration' => 1,
            'max_outbound_count' => $maxOutboundCount,
            'outbound_count' => 0,
        ]);
    }
}
