<?php

namespace App\Domain\Crew\Events;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;

/**
 * Fired after a crew execution has been dispatched.
 */
class CrewExecuted
{
    public function __construct(
        public readonly Crew $crew,
        public readonly CrewExecution $execution,
    ) {}
}
