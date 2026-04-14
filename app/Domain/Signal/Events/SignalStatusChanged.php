<?php

namespace App\Domain\Signal\Events;

use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignalStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Signal $signal,
        public readonly SignalStatus $oldStatus,
        public readonly SignalStatus $newStatus,
    ) {}
}
