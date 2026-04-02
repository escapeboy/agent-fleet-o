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

        $steps = $output['steps'] ?? $output['plan'] ?? null;

        if ($steps === null) {
            $errors[] = 'Missing required "steps" or "plan" key in output.';
        } elseif (! is_array($steps) || empty($steps)) {
            $errors[] = 'The "steps"/"plan" value must be a non-empty array.';
        } else {
            foreach ($steps as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }
                if (! isset($step['description']) || ! is_string($step['description']) || trim($step['description']) === '') {
                    $errors[] = "Step [{$index}] is missing a valid \"description\" key.";
                }
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
