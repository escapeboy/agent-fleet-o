<?php

namespace App\Infrastructure\AI\LoopDetection\Events;

use App\Domain\Agent\Models\AiRun;
use App\Infrastructure\AI\LoopDetection\LoopSignal;
use Illuminate\Foundation\Events\Dispatchable;

class AgentLoopDetected
{
    use Dispatchable;

    public function __construct(
        public readonly AiRun $run,
        public readonly LoopSignal $signal,
    ) {}
}
