<?php

declare(strict_types=1);

namespace App\Domain\Signal\Listeners;

use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Jobs\ClassifyAutoSignalJob;

final class DispatchAutoTriageOnSignalIngested
{
    public function handle(SignalIngested $event): void
    {
        if (! config('signals.bug_report.triage_classifier_enabled', false)) {
            return;
        }

        $signal = $event->signal;

        if ($signal->source_type !== 'bug_report') {
            return;
        }

        if ($signal->reported_type !== 'auto') {
            return;
        }

        ClassifyAutoSignalJob::dispatch($signal->id);
    }
}
