<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class SyncSignalStatusOnExperimentComplete
{
    public function __construct(
        private readonly UpdateSignalStatusAction $updateStatus,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        $experiment = $event->experiment;

        if ($experiment->status !== ExperimentStatus::Completed) {
            return;
        }

        $signal = Signal::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('source_type', 'bug_report')
            ->first();

        if (! $signal) {
            return;
        }

        try {
            $this->updateStatus->execute(
                signal: $signal,
                newStatus: SignalStatus::Review,
                comment: 'Agent completed the fix. Ready for human review.',
            );
        } catch (\Throwable $e) {
            Log::warning('SyncSignalStatusOnExperimentComplete: failed to update signal status', [
                'signal_id' => $signal->id,
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
