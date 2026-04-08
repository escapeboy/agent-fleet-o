<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Services\ReasoningBankService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class RecordReasoningBankEntry implements ShouldQueue
{
    public string $queue = 'default';

    public function __construct(private readonly ReasoningBankService $bank) {}

    public function handle(ExperimentTransitioned $event): void
    {
        if ($event->toState !== ExperimentStatus::Completed) {
            return;
        }

        try {
            $this->bank->record($event->experiment);
        } catch (\Throwable $e) {
            Log::warning('ReasoningBank: failed to record entry', [
                'experiment_id' => $event->experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
