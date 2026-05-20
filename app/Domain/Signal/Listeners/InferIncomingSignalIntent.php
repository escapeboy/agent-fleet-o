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
        // Sentry signals arrive as source_type 'integration' with source_identifier
        // 'sentry' — the watchdog owns them, not the intent classifier.
        if (in_array($signal->source_type, ['bug_report', 'manual'], true)
            || $signal->source_identifier === 'sentry') {
            return;
        }

        ClassifySignalIntentJob::dispatch($signal->id);
    }
}
