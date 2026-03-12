<?php

namespace App\Domain\Crew\Events;

use App\Domain\Crew\Models\Crew;

/**
 * Fired before a crew execution begins.
 */
class CrewExecuting
{
    public bool $cancel = false;

    public ?string $cancelReason = null;

    public function __construct(
        public readonly Crew $crew,
        public array $input,
    ) {}

    public function cancel(string $reason = 'Cancelled by plugin'): void
    {
        $this->cancel = true;
        $this->cancelReason = $reason;
    }
}
