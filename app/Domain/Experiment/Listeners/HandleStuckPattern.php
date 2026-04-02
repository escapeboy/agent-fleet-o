<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Events\StuckPatternDetected;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class HandleStuckPattern
{
    public function __construct(
        private readonly PauseExperimentAction $pause,
        private readonly KillExperimentAction $kill,
        private readonly NotificationService $notifications,
    ) {}

    public function handle(StuckPatternDetected $event): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($event->experimentId);

        if (! $experiment || $experiment->status->isTerminal()) {
            return;
        }

        $action = $event->pattern->defaultAction();

        match ($action) {
            'notify' => $this->handleNotify($experiment, $event),
            'pause' => $this->handlePause($experiment, $event),
            'kill' => $this->handleKill($experiment, $event),
            default => null,
        };
    }

    private function handleNotify(Experiment $experiment, StuckPatternDetected $event): void
    {
        Log::warning('StuckPatternDetected: notifying team', [
            'experiment_id' => $event->experimentId,
            'pattern' => $event->pattern->value,
            'severity' => $event->severity,
        ]);

        $this->notifyTeam($experiment, $event);
    }

    private function handlePause(Experiment $experiment, StuckPatternDetected $event): void
    {
        $this->notifyTeam($experiment, $event);

        try {
            $this->pause->execute(
                experiment: $experiment,
                reason: "Auto-paused: {$event->pattern->value} detected ({$event->severity} severity)",
            );

            Log::warning('StuckPatternDetected: auto-paused experiment', [
                'experiment_id' => $event->experimentId,
                'pattern' => $event->pattern->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('StuckPatternDetected: failed to pause experiment', [
                'experiment_id' => $event->experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleKill(Experiment $experiment, StuckPatternDetected $event): void
    {
        $this->notifyTeam($experiment, $event);

        try {
            $this->kill->execute(
                experiment: $experiment,
                reason: "Auto-killed: {$event->pattern->value} detected ({$event->severity} severity)",
            );

            Log::warning('StuckPatternDetected: auto-killed experiment', [
                'experiment_id' => $event->experimentId,
                'pattern' => $event->pattern->value,
            ]);
        } catch (\Throwable $e) {
            Log::error('StuckPatternDetected: failed to kill experiment', [
                'experiment_id' => $event->experimentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyTeam(Experiment $experiment, StuckPatternDetected $event): void
    {
        $team = $experiment->team ?? Team::find($experiment->team_id);

        if (! $team) {
            return;
        }

        $admins = $team->users()
            ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
            ->get();

        $patternLabel = str_replace('_', ' ', $event->pattern->value);

        foreach ($admins as $admin) {
            $this->notifications->notify(
                userId: $admin->id,
                teamId: $team->id,
                type: 'experiment',
                title: "Stuck pattern detected: {$patternLabel}",
                body: "Experiment \"{$experiment->title}\" has a {$event->severity}-severity {$patternLabel} pattern.",
                actionUrl: "/experiments/{$experiment->id}",
                data: $event->details,
            );
        }
    }
}
