<?php

namespace App\Console\Commands;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentTask;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Notifications\StuckExperimentNotification;
use App\Domain\Experiment\Pipeline\CollectMetrics;
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Experiment\Pipeline\RunEvaluationStage;
use App\Domain\Experiment\Pipeline\RunPlanningStage;
use App\Domain\Experiment\Pipeline\RunScoringStage;
use App\Domain\Experiment\Services\CheckpointManager;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecoverStuckTasks extends Command
{
    protected $signature = 'tasks:recover-stuck {--timeout=10 : Minutes after which a running task is considered stuck}';

    protected $description = 'Detect and recover experiment tasks and experiments stuck in processing states';

    /**
     * Map processing experiment states to the job class that should be re-dispatched.
     */
    private const STATE_JOB_MAP = [
        'scoring' => RunScoringStage::class,
        'planning' => RunPlanningStage::class,
        'collecting_metrics' => CollectMetrics::class,
        'evaluating' => RunEvaluationStage::class,
    ];

    public function handle(): int
    {
        $timeoutMinutes = (int) $this->option('timeout');
        $cutoff = now()->subMinutes($timeoutMinutes);

        $recovered = $this->recoverStuckTasks($cutoff);
        $resolved = $this->resolveStuckStages();
        $steps = $this->recoverStuckPlaybookSteps();
        $experiments = $this->recoverStuckExperiments();

        $this->info("Recovered {$recovered} stuck task(s), resolved {$resolved} stuck stage(s), {$steps} stuck step(s), {$experiments} stuck experiment(s).");

        return self::SUCCESS;
    }

    /**
     * Find tasks stuck in "running" or "queued" status beyond the timeout.
     */
    private function recoverStuckTasks(\DateTimeInterface $cutoff): int
    {
        // Running tasks that started before the cutoff
        $stuckRunning = ExperimentTask::withoutGlobalScopes()
            ->where('status', ExperimentTaskStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->get();

        foreach ($stuckRunning as $task) {
            $task->update([
                'status' => ExperimentTaskStatus::Failed,
                'error' => 'Recovered by tasks:recover-stuck — task exceeded timeout without completing',
                'completed_at' => now(),
            ]);

            Log::warning('RecoverStuckTasks: Marked stuck running task as failed', [
                'task_id' => $task->id,
                'experiment_id' => $task->experiment_id,
                'name' => $task->name,
                'started_at' => $task->started_at,
            ]);
        }

        // Queued tasks created before the cutoff that were never picked up
        $stuckQueued = ExperimentTask::withoutGlobalScopes()
            ->where('status', ExperimentTaskStatus::Queued)
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stuckQueued as $task) {
            $task->update([
                'status' => ExperimentTaskStatus::Failed,
                'error' => 'Recovered by tasks:recover-stuck — task was queued but never started',
                'completed_at' => now(),
            ]);

            Log::warning('RecoverStuckTasks: Marked stuck queued task as failed', [
                'task_id' => $task->id,
                'experiment_id' => $task->experiment_id,
                'name' => $task->name,
            ]);
        }

        return $stuckRunning->count() + $stuckQueued->count();
    }

    /**
     * Find building stages stuck in "running" where all tasks are terminal.
     * This happens when the batch callbacks fail to fire (e.g., batch_id lost).
     */
    private function resolveStuckStages(): int
    {
        $stuckStages = ExperimentStage::withoutGlobalScopes()
            ->where('stage', 'building')
            ->where('status', StageStatus::Running)
            ->get();

        $resolved = 0;

        foreach ($stuckStages as $stage) {
            $tasks = ExperimentTask::withoutGlobalScopes()
                ->where('experiment_id', $stage->experiment_id)
                ->where('stage', 'building')
                ->get();

            if ($tasks->isEmpty()) {
                continue;
            }

            // Check if all tasks are in a terminal state
            $allTerminal = $tasks->every(fn ($t) => in_array($t->status, [
                ExperimentTaskStatus::Completed,
                ExperimentTaskStatus::Failed,
                ExperimentTaskStatus::Skipped,
            ]));

            if (! $allTerminal) {
                // Check if the batch still exists and has pending work
                $batchId = $stage->output_snapshot['batch_id'] ?? null;
                if ($batchId) {
                    $batch = Bus::findBatch($batchId);
                    if ($batch && ! $batch->finished()) {
                        continue; // Batch is still running, skip
                    }
                }

                // No batch or batch finished — but tasks aren't terminal.
                // This shouldn't happen after recoverStuckTasks runs, so skip for now.
                continue;
            }

            // All tasks are terminal — determine outcome
            $failedCount = $tasks->where('status', ExperimentTaskStatus::Failed)->count();
            $completedCount = $tasks->where('status', ExperimentTaskStatus::Completed)->count();

            // Skip any remaining pending/queued tasks
            ExperimentTask::withoutGlobalScopes()
                ->where('experiment_id', $stage->experiment_id)
                ->where('stage', 'building')
                ->whereIn('status', [ExperimentTaskStatus::Pending, ExperimentTaskStatus::Queued])
                ->update([
                    'status' => ExperimentTaskStatus::Skipped,
                    'error' => 'Skipped by recovery — stage resolved',
                    'completed_at' => now(),
                ]);

            if ($failedCount === 0) {
                // All completed — stage succeeded
                $builtArtifacts = $tasks->where('status', ExperimentTaskStatus::Completed)
                    ->map(fn ($t) => $t->output_data)
                    ->filter()
                    ->values()
                    ->toArray();

                $stage->update([
                    'status' => StageStatus::Completed,
                    'completed_at' => now(),
                    'duration_ms' => $stage->started_at ? (int) $stage->started_at->diffInMilliseconds(now()) : null,
                    'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                        'artifacts_built' => $builtArtifacts,
                        'recovered_by' => 'tasks:recover-stuck',
                    ]),
                ]);

                $this->transitionExperiment($stage->experiment_id, ExperimentStatus::Building, ExperimentStatus::AwaitingApproval, "All {$completedCount} artifacts built (recovered by scheduler)");
            } else {
                // Some failed — stage failed
                $stage->update([
                    'status' => StageStatus::Failed,
                    'completed_at' => now(),
                    'duration_ms' => $stage->started_at ? (int) $stage->started_at->diffInMilliseconds(now()) : null,
                    'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                        'error' => "{$failedCount} artifact(s) failed to build",
                        'recovered_by' => 'tasks:recover-stuck',
                    ]),
                ]);

                $this->transitionExperiment($stage->experiment_id, ExperimentStatus::Building, ExperimentStatus::BuildingFailed, "{$failedCount} artifact(s) failed (recovered by scheduler)");
            }

            Log::info('RecoverStuckTasks: Resolved stuck building stage', [
                'experiment_id' => $stage->experiment_id,
                'completed' => $completedCount,
                'failed' => $failedCount,
            ]);

            $resolved++;
        }

        return $resolved;
    }

    /**
     * Find playbook steps stuck in "running" using heartbeat-aware detection.
     */
    private function recoverStuckPlaybookSteps(): int
    {
        $heartbeatStale = now()->subMinutes(2);
        $noHeartbeatStale = now()->subMinutes(5);

        $stuckSteps = PlaybookStep::withoutGlobalScopes()
            ->where('status', 'running')
            ->where(function ($query) use ($heartbeatStale, $noHeartbeatStale) {
                $query
                    ->where(function ($q) use ($heartbeatStale) {
                        $q->whereNotNull('last_heartbeat_at')
                            ->where('last_heartbeat_at', '<', $heartbeatStale);
                    })
                    ->orWhere(function ($q) use ($noHeartbeatStale) {
                        $q->whereNull('last_heartbeat_at')
                            ->where('started_at', '<', $noHeartbeatStale);
                    });
            })
            ->get();

        $recovered = 0;
        $checkpointManager = app(CheckpointManager::class);

        foreach ($stuckSteps as $step) {
            if ($step->hasCheckpoint()) {
                $step->update([
                    'status' => 'pending',
                    'worker_id' => null,
                ]);

                $checkpointManager->writeCheckpoint($step->id, [
                    'phase' => 'recovered',
                    'recovered_at' => now()->toIso8601String(),
                    'previous_worker' => $step->worker_id,
                ]);

                ExecutePlaybookStepJob::dispatch($step->id, $step->experiment_id, $step->experiment?->team_id)
                    ->onQueue('ai-calls');

                Log::info('RecoverStuckTasks: Re-dispatched stuck playbook step with checkpoint', [
                    'step_id' => $step->id,
                    'experiment_id' => $step->experiment_id,
                    'last_heartbeat_at' => $step->last_heartbeat_at,
                ]);
            } else {
                $step->update([
                    'status' => 'failed',
                    'error_message' => 'Recovered by tasks:recover-stuck — step exceeded timeout without heartbeat or checkpoint',
                    'completed_at' => now(),
                    'worker_id' => null,
                ]);

                Log::warning('RecoverStuckTasks: Marked stuck playbook step as failed (no checkpoint)', [
                    'step_id' => $step->id,
                    'experiment_id' => $step->experiment_id,
                    'started_at' => $step->started_at,
                ]);
            }

            $recovered++;
        }

        return $recovered;
    }

    /**
     * Detect experiments stuck in processing states beyond configurable timeouts.
     *
     * Escalation ladder:
     * 1. Auto-retry (attempts 1-2): Re-dispatch the stage job
     * 2. Notify (attempt 3+): Send notification to experiment creator + team admins
     * 3. Auto-pause (attempt 4+): Transition experiment to Paused
     */
    private function recoverStuckExperiments(): int
    {
        if (! config('experiments.recovery.enabled', true)) {
            return 0;
        }

        $timeouts = config('experiments.recovery.timeouts', []);
        $maxAttempts = config('experiments.recovery.max_recovery_attempts', 4);
        $notifyAfter = config('experiments.recovery.notify_after_attempts', 3);
        $pauseAfter = config('experiments.recovery.pause_after_attempts', 4);

        $processingStates = [
            ExperimentStatus::Scoring,
            ExperimentStatus::Planning,
            ExperimentStatus::Building,
            ExperimentStatus::Executing,
            ExperimentStatus::CollectingMetrics,
            ExperimentStatus::Evaluating,
        ];

        $recovered = 0;

        foreach ($processingStates as $state) {
            $timeoutSeconds = $timeouts[$state->value] ?? 900; // Default 15 min
            $cutoff = now()->subSeconds($timeoutSeconds);

            $stuckExperiments = Experiment::withoutGlobalScopes()
                ->where('status', $state)
                ->where('updated_at', '<', $cutoff)
                ->get();

            foreach ($stuckExperiments as $experiment) {
                $recovered += $this->handleStuckExperiment(
                    $experiment,
                    $state,
                    $maxAttempts,
                    $notifyAfter,
                    $pauseAfter,
                );
            }
        }

        return $recovered;
    }

    private function handleStuckExperiment(
        Experiment $experiment,
        ExperimentStatus $state,
        int $maxAttempts,
        int $notifyAfter,
        int $pauseAfter,
    ): int {
        // Use lockForUpdate to prevent race conditions with normal pipeline
        return DB::transaction(function () use ($experiment, $state, $notifyAfter, $pauseAfter) {
            $fresh = Experiment::withoutGlobalScopes()->lockForUpdate()->find($experiment->id);
            if (! $fresh || $fresh->status !== $state) {
                return 0; // State changed since detection
            }

            // Find or get the latest stage for this experiment in the current state
            $stage = ExperimentStage::withoutGlobalScopes()
                ->where('experiment_id', $fresh->id)
                ->where('stage', $state->value)
                ->orderByDesc('iteration')
                ->first();

            $attempts = $stage ? $stage->recovery_attempts + 1 : 1;
            $stuckDuration = $fresh->updated_at->diffForHumans(now(), true);

            // Update recovery tracking on the stage
            if ($stage) {
                $stage->update([
                    'recovery_attempts' => $attempts,
                    'last_recovery_at' => now(),
                    'recovery_reason' => 'timeout',
                ]);
            }

            Log::warning('RecoverStuckTasks: Detected stuck experiment', [
                'experiment_id' => $fresh->id,
                'state' => $state->value,
                'recovery_attempt' => $attempts,
                'stuck_duration' => $stuckDuration,
            ]);

            // Escalation ladder
            if ($attempts >= $pauseAfter) {
                // Step 3: Auto-pause
                $this->pauseStuckExperiment($fresh, $state, $attempts, $stuckDuration);
            } elseif ($attempts >= $notifyAfter) {
                // Step 2: Notify + retry
                $this->notifyStuckExperiment($fresh, $attempts, $state->value, $stuckDuration);
                $this->retryStuckExperiment($fresh, $state);
            } else {
                // Step 1: Silent retry
                $this->retryStuckExperiment($fresh, $state);
            }

            return 1;
        });
    }

    private function retryStuckExperiment(Experiment $experiment, ExperimentStatus $state): void
    {
        $jobClass = self::STATE_JOB_MAP[$state->value] ?? null;

        if ($jobClass) {
            // For states with direct job mapping, re-dispatch
            $jobClass::dispatch($experiment->id);

            Log::info('RecoverStuckTasks: Re-dispatched stage job for stuck experiment', [
                'experiment_id' => $experiment->id,
                'state' => $state->value,
                'job' => class_basename($jobClass),
            ]);
        } elseif ($state === ExperimentStatus::Building) {
            // Building is handled by resolveStuckStages, skip here
            Log::debug('RecoverStuckTasks: Building state handled by resolveStuckStages', [
                'experiment_id' => $experiment->id,
            ]);
        } elseif ($state === ExperimentStatus::Executing) {
            // Executing may use playbooks — handled by recoverStuckPlaybookSteps
            Log::debug('RecoverStuckTasks: Executing state handled by recoverStuckPlaybookSteps', [
                'experiment_id' => $experiment->id,
            ]);
        }

        // Touch updated_at to reset the timeout clock
        $experiment->touch();
    }

    private function notifyStuckExperiment(Experiment $experiment, int $attempts, string $stuckState, string $stuckDuration): void
    {
        $notification = new StuckExperimentNotification($experiment, $attempts, $stuckState, $stuckDuration);

        // Notify experiment creator
        if ($experiment->user_id) {
            $creator = User::find($experiment->user_id);
            $creator?->notify($notification);
        }

        // Notify team admins and owner
        $team = $experiment->team ?? Team::find($experiment->team_id);
        if ($team) {
            $admins = $team->users()
                ->wherePivotIn('role', [TeamRole::Owner->value, TeamRole::Admin->value])
                ->where('id', '!=', $experiment->user_id) // Don't double-notify creator
                ->get();

            foreach ($admins as $admin) {
                $admin->notify($notification);
            }
        }
    }

    private function pauseStuckExperiment(Experiment $experiment, ExperimentStatus $state, int $attempts, string $stuckDuration): void
    {
        // Send final notification before pausing
        $this->notifyStuckExperiment($experiment, $attempts, $state->value, $stuckDuration);

        try {
            app(TransitionExperimentAction::class)->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Paused,
                reason: "Auto-paused after {$attempts} recovery attempts — stuck in {$state->value} for {$stuckDuration}",
            );

            Log::warning('RecoverStuckTasks: Auto-paused stuck experiment', [
                'experiment_id' => $experiment->id,
                'state' => $state->value,
                'recovery_attempts' => $attempts,
            ]);
        } catch (\Throwable $e) {
            Log::error('RecoverStuckTasks: Failed to auto-pause experiment', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function transitionExperiment(string $experimentId, ExperimentStatus $expectedState, ExperimentStatus $toState, string $reason): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
        if (! $experiment || $experiment->status !== $expectedState) {
            return;
        }

        try {
            app(TransitionExperimentAction::class)->execute(
                experiment: $experiment,
                toState: $toState,
                reason: $reason,
            );
        } catch (\Throwable $e) {
            Log::error('RecoverStuckTasks: Failed to transition experiment', [
                'experiment_id' => $experimentId,
                'to_state' => $toState->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
