<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Jobs\ClassifySignalIntentJob;

class InferIncomingSignalIntent
{
    public function handle(SignalIngested $event): void
    {
        $signal = $event->signal;

        // Skip obviously low-signal sources — bug reports have their own structured
        // extraction flow, manual signals are already user-classified, and Sentry
        // issues are always blockers (LLM intent classification adds nothing).
        if (in_array($signal->source_type, ['bug_report', 'manual', 'sentry'], true)) {
            return;
        }

        ClassifySignalIntentJob::dispatch($signal->id);
    }
}
