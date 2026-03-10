<?php

namespace App\Domain\Budget\Listeners;

use App\Domain\Budget\Actions\CheckBudgetAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Shared\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class PauseOnBudgetExceeded
{
    public function __construct(
        private readonly CheckBudgetAction $checkBudget,
        private readonly PauseExperimentAction $pause,
        private readonly NotificationService $notifications,
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
                reason: 'Auto-paused: '.$result['reason'],
            );

            if ($experiment->team_id) {
                $this->notifications->notifyTeam(
                    teamId: $experiment->team_id,
                    type: 'budget.exceeded',
                    title: 'Experiment Auto-Paused: Budget Exceeded',
                    body: sprintf(
                        '"%s" was paused automatically because the budget was exceeded (%s%% used).',
                        $experiment->name ?? 'Experiment',
                        round($result['pct_used'] ?? 100, 0),
                    ),
                    actionUrl: '/experiments/'.$experiment->id,
                    data: ['experiment_id' => $experiment->id, 'url' => '/experiments/'.$experiment->id],
                );
            }
        } catch (\Throwable $e) {
            Log::error('PauseOnBudgetExceeded: Failed to auto-pause', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
