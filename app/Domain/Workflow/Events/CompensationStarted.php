<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Experiment\Models\Experiment;

class CompensationStarted
{
    public function __construct(
        public readonly Experiment $experiment,
        public readonly int $totalCompensations,
    ) {}
}
