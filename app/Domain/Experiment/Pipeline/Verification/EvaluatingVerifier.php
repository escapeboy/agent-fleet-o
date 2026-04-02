<?php

namespace App\Domain\Experiment\Pipeline\Verification;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;

class EvaluatingVerifier implements StageVerifier
{
    public function verify(Experiment $experiment, ExperimentStage $stage): array
    {
        $output = $stage->output_snapshot ?? [];
        $errors = [];

        $hasVerdict = array_key_exists('verdict', $output) || array_key_exists('evaluation', $output);
        if (! $hasVerdict) {
            $errors[] = 'Missing required "verdict" or "evaluation" key in output.';
        }

        $scores = $output['score'] ?? $output['scores'] ?? null;
        if ($scores === null) {
            $errors[] = 'Missing required "score" or "scores" key in output.';
        } elseif (is_numeric($scores)) {
            // single score — valid
        } elseif (is_array($scores)) {
            foreach ($scores as $key => $value) {
                if (! is_numeric($value)) {
                    $errors[] = "Score [{$key}] must be numeric, got: ".gettype($value).'.';
                }
            }
        } else {
            $errors[] = 'The "score"/"scores" value must be numeric or an array of numeric values.';
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
