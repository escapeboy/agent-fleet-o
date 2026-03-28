<?php

namespace App\Domain\Workflow\Events;

use App\Domain\Experiment\Models\Experiment;

class CompensationCompleted
{
    public function __construct(
        public readonly Experiment $experiment,
        public readonly int $totalCompensations,
        public readonly int $succeededCount,
        public readonly int $failedCount,
    ) {}
}
