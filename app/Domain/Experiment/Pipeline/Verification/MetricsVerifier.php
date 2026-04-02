<?php

namespace App\Domain\Experiment\Pipeline\Verification;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;

class MetricsVerifier implements StageVerifier
{
    public function verify(Experiment $experiment, ExperimentStage $stage): array
    {
        $output = $stage->output_snapshot ?? [];
        $errors = [];

        if (! array_key_exists('metrics', $output)) {
            $errors[] = 'Missing required "metrics" key in output.';
        } elseif (! is_array($output['metrics']) || empty($output['metrics'])) {
            $errors[] = 'The "metrics" value must be a non-empty array.';
        } else {
            foreach ($output['metrics'] as $index => $metric) {
                if (! is_array($metric)) {
                    continue;
                }
                $value = $metric['value'] ?? null;
                if ($value === null || ! is_numeric($value)) {
                    $key = $metric['name'] ?? $index;
                    $errors[] = "Metric [{$key}] is missing a numeric \"value\".";
                }
            }
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
