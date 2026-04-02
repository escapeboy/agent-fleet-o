<?php

namespace App\Domain\Experiment\Pipeline\Verification;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;

class ScoringVerifier implements StageVerifier
{
    public function verify(Experiment $experiment, ExperimentStage $stage): array
    {
        $output = $stage->output_snapshot ?? [];
        $errors = [];

        if (! array_key_exists('score', $output)) {
            $errors[] = 'Missing required "score" key in output.';
        } elseif (! is_numeric($output['score'])) {
            $errors[] = 'The "score" value must be numeric, got: '.gettype($output['score']).'.';
        } elseif ($output['score'] < 0 || $output['score'] > 100) {
            $errors[] = 'The "score" value must be between 0 and 100, got: '.$output['score'].'.';
        }

        if (! array_key_exists('reasoning', $output)) {
            $errors[] = 'Missing required "reasoning" key in output.';
        } elseif (! is_string($output['reasoning']) || trim($output['reasoning']) === '') {
            $errors[] = 'The "reasoning" value must be a non-empty string.';
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
