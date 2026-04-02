<?php

namespace App\Domain\Experiment\Pipeline\Verification;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Pipeline\Contracts\StageVerifier;

class BuildingVerifier implements StageVerifier
{
    public function verify(Experiment $experiment, ExperimentStage $stage): array
    {
        $output = $stage->output_snapshot ?? [];
        $errors = [];

        $hasArtifacts = $experiment->artifacts()->exists();
        $hasContent = ! empty($output) && $output !== [];

        if (! $hasArtifacts && ! $hasContent) {
            $errors[] = 'Stage produced no artifacts and output_snapshot is empty.';
        }

        if (array_key_exists('error', $output) && ! empty($output['error'])) {
            $errors[] = 'Output contains an "error" key: '.$output['error'];
        }

        return ['passed' => empty($errors), 'errors' => $errors];
    }
}
