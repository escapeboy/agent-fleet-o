<?php

namespace App\Domain\Experiment\Events;

use App\Domain\Experiment\Enums\StuckPattern;

class StuckPatternDetected
{
    public function __construct(
        public readonly string $experimentId,
        public readonly StuckPattern $pattern,
        public readonly string $severity,
        public readonly array $details,
    ) {}
}
