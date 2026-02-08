<?php

namespace App\Domain\Budget\Listeners;

use App\Domain\Budget\Actions\CheckBudgetAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use Illuminate\Support\Facades\Log;

class PauseOnBudgetExceeded
{
    public function __construct(
        private readonly CheckBudgetAction $checkBudget,
        private readonly PauseExperimentAction $pause,
    ) {}

    public function handle(ExperimentTransitioned $event): void
    {
        $experiment = $event->experiment;

        // Only check budget on active states (not terminal, not already paused)
        if ($experiment->status->isTerminal() || $experiment->status === ExperimentStatus::Paused) {
            return;
        }

        $result = $this->checkBudget->execute($experiment);

        if ($result['ok']) {
            return;
        }

        Log::warning('PauseOnBudgetExceeded: Auto-pausing experiment', [
            'experiment_id' => $experiment->id,
            'reason' => $result['reason'],
            'pct_used' => $result['pct_used'],
        ]);

        try {
            $this->pause->execute(
                experiment: $experiment,
                reason: 'Auto-paused: ' . $result['reason'],
            );
        } catch (\Throwable $e) {
            Log::error('PauseOnBudgetExceeded: Failed to auto-pause', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
