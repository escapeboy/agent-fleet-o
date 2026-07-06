<?php

namespace App\Domain\Agent\Observers;

use App\Domain\Agent\Models\AiRun;
use App\Infrastructure\AI\LoopDetection\Events\AgentLoopDetected;
use App\Infrastructure\AI\LoopDetection\LoopDetector;
use Illuminate\Support\Facades\Log;

class AiRunObserver
{
    public function __construct(private readonly LoopDetector $detector) {}

    public function created(AiRun $run): void
    {
        if (! (bool) config('loop_detection.enabled', false)) {
            return;
        }

        // Loop detection must never break the inference/recording path.
        try {
            $signal = $this->detector->inspect($run);

            if ($signal->tripped()) {
                AgentLoopDetected::dispatch($run, $signal);
            }
        } catch (\Throwable $e) {
            Log::warning('AiRunObserver: loop detection failed', [
                'ai_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
