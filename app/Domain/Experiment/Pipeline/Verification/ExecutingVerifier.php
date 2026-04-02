<?php

namespace App\Domain\Experiment\Pipeline\Verification;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;

class ExecutingVerifier implements StageVerifier
{
    public function verify(Experiment $experiment, ExperimentStage $stage): array
    {
        $output = $stage->output_snapshot ?? [];
        $errors = [];

        // Check output is not just an error wrapper
        $meaningfulKeys = array_diff(array_keys($output), ['error', 'model_tier']);
        if (empty($meaningfulKeys)) {
            $errors[] = 'Output contains no meaningful content beyond error/metadata keys.';
        }

        // If the experiment defines an expected output schema, validate against it
        $config = $experiment->config ?? [];
        $expectedSchema = $config['expected_output_schema'] ?? null;

        if (is_array($expectedSchema) && ! empty($expectedSchema)) {
            foreach ($expectedSchema as $requiredKey => $type) {
                if (is_string($requiredKey) && ! array_key_exists($requiredKey, $output)) {
                    $errors[] = "Expected output key \"{$requiredKey}\" is missing.";
                }
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
