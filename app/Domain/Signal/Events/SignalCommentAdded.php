<?php

namespace App\Domain\Signal\Events;

use App\Domain\Signal\Models\SignalComment;
use Illuminate\Foundation\Events\Dispatchable;

class SignalCommentAdded
{
    use Dispatchable;

    public function __construct(public readonly SignalComment $comment) {}
}
