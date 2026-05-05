<?php

namespace App\Domain\Signal\Events;

use App\Domain\Signal\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SignalAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Signal $signal,
        public readonly ?User $assignee,
        public readonly User $actor,
        public readonly ?string $reason = null,
    ) {}
}
