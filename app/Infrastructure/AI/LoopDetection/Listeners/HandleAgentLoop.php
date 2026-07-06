<?php

namespace App\Infrastructure\AI\LoopDetection\Listeners;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Services\NotificationService;
use App\Infrastructure\AI\LoopDetection\Events\AgentLoopDetected;
use App\Infrastructure\AI\LoopDetection\LoopSignal;
use Illuminate\Support\Facades\Log;
use Sentry\Severity;

/**
 * Trips the runaway-agent circuit breaker. Mirrors PauseOnBudgetExceeded:
 * pause (default) or kill the parent experiment, alert the team, and record a
 * Sentry security event. 'alert' mode observes without stopping execution.
 */
class HandleAgentLoop
{
    public function __construct(
        private readonly PauseExperimentAction $pause,
        private readonly KillExperimentAction $kill,
        private readonly NotificationService $notifications,
    ) {}

    public function handle(AgentLoopDetected $event): void
    {
        $run = $event->run;
        $signal = $event->signal;
        $action = (string) config('loop_detection.on_trip', 'pause');

        Log::warning('LoopDetection: agent loop detected', [
            'ai_run_id' => $run->id,
            'experiment_id' => $run->experiment_id,
            'team_id' => $run->team_id,
            'type' => $signal->type->value,
            'reason' => $signal->reason,
            'on_trip' => $action,
        ]);

        $this->captureSecurityEvent($signal);

        // No experiment to stop, or observe-only mode: alert and return.
        if ($action === 'alert' || ! $run->experiment_id) {
            $this->notify($run, $signal, paused: false);

            return;
        }

        $experiment = Experiment::find($run->experiment_id);

        if (! $experiment || $experiment->status->isTerminal() || $experiment->status === ExperimentStatus::Paused) {
            return;
        }

        try {
            $reason = sprintf('Auto-%s: agent loop (%s) — %s', $action === 'kill' ? 'killed' : 'paused', $signal->type->value, $signal->reason);

            if ($action === 'kill') {
                $this->kill->execute(experiment: $experiment, reason: $reason);
            } else {
                $this->pause->execute(experiment: $experiment, reason: $reason);
            }

            $this->notify($run, $signal, paused: true);
        } catch (\Throwable $e) {
            Log::error('LoopDetection: failed to '.$action.' experiment', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notify(AiRun $run, LoopSignal $signal, bool $paused): void
    {
        if (! $run->team_id) {
            return;
        }

        $verb = $paused ? 'stopped' : 'flagged';

        $this->notifications->notifyTeam(
            teamId: $run->team_id,
            type: 'loop.detected',
            title: 'Agent Loop Detected',
            body: sprintf('A runaway agent loop (%s) was %s: %s', $signal->type->value, $verb, $signal->reason),
            actionUrl: $run->experiment_id ? '/experiments/'.$run->experiment_id : null,
            data: [
                'ai_run_id' => $run->id,
                'experiment_id' => $run->experiment_id,
                'signal_type' => $signal->type->value,
                'reason' => $signal->reason,
            ],
        );
    }

    private function captureSecurityEvent(LoopSignal $signal): void
    {
        if (! app()->bound('sentry')) {
            return;
        }

        try {
            \Sentry\captureMessage(
                'agent_loop_detected: '.$signal->type->value.' — '.$signal->reason,
                Severity::warning(),
            );
        } catch (\Throwable) {
            // Best-effort telemetry; never let it break the trip handler.
        }
    }
}
