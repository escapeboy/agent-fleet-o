<?php

namespace App\Domain\Signal\Events;

use App\Domain\Signal\Models\Signal;

/**
 * Fired when a new occurrence is merged into a signal that was already Resolved —
 * i.e. a shipped fix did not survive and the error recurred in production.
 */
class SignalFixRecurred
{
    public function __construct(
        public readonly Signal $signal,
    ) {}
}
