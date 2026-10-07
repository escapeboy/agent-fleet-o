<?php

declare(strict_types=1);

namespace App\Domain\Signal\Listeners;

use App\Domain\Shared\Services\TeamFeatures;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Jobs\ClassifyAutoSignalJob;

final class DispatchAutoTriageOnSignalIngested
{
    public function handle(SignalIngested $event): void
    {
        $signal = $event->signal;

        if (! app(TeamFeatures::class)->enabled('bug_report_triage', $signal->team_id)) {
            return;
        }

        if ($signal->source_type !== 'bug_report') {
            return;
        }

        if ($signal->reported_type !== 'auto') {
            return;
        }

        ClassifyAutoSignalJob::dispatch($signal->id);
    }
}
