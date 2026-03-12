<?php

namespace App\Domain\Signal\Events;

use App\Domain\Signal\Models\Signal;

/**
 * Fired after a new signal has been successfully persisted.
 */
class SignalIngested
{
    public function __construct(
        public readonly Signal $signal,
    ) {}
}
