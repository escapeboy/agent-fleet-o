<?php

namespace App\Domain\Experiment\Pipeline\Verification;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;

class PlanningVerifier implements StageVerifier
{
    public function verify(Experiment $experiment, ExperimentStage $stage): array
    {
        $output = $stage->output_snapshot ?? [];
        $errors = [];

        $planSummary = $output['plan_summary'] ?? null;

        if ($planSummary === null) {
            $errors[] = 'Missing required "plan_summary" key in output.';
        } elseif (! is_string($planSummary) || trim($planSummary) === '') {
            $errors[] = 'The "plan_summary" value must be a non-empty string.';
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
