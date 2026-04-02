<?php

namespace App\Domain\Experiment\Pipeline\Contracts;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;

interface StageVerifier
{
    /**
     * Verify the output of a completed stage.
     *
     * @return array{passed: bool, errors: array<string>}
     */
    public function verify(Experiment $experiment, ExperimentStage $stage): array;
}
