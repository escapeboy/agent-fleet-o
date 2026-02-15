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
use App\Domain\Experiment\Pipeline\ExecutePlaybookStepJob;
use App\Domain\Experiment\Services\CheckpointManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class RecoverStuckTasks extends Command
{
    protected $signature = 'tasks:recover-stuck {--timeout=10 : Minutes after which a running task is considered stuck}';

    protected $description = 'Detect and recover experiment tasks stuck in running/queued status';

    public function handle(): int
    {
        $timeoutMinutes = (int) $this->option('timeout');
        $cutoff = now()->subMinutes($timeoutMinutes);

        $recovered = $this->recoverStuckTasks($cutoff);
        $resolved = $this->resolveStuckStages();
        $steps = $this->recoverStuckPlaybookSteps();

        $this->info("Recovered {$recovered} stuck task(s), resolved {$resolved} stuck stage(s), {$steps} stuck step(s).");

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

                $this->transitionExperiment($stage->experiment_id, ExperimentStatus::AwaitingApproval, "All {$completedCount} artifacts built (recovered by scheduler)");
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

                $this->transitionExperiment($stage->experiment_id, ExperimentStatus::BuildingFailed, "{$failedCount} artifact(s) failed (recovered by scheduler)");
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
     *
     * - Steps with stale heartbeat (>2 min) are considered stuck.
     * - Steps with no heartbeat and old started_at (>5 min) are considered stuck.
     * - Steps with a checkpoint are reset to "pending" and re-dispatched.
     * - Steps without a checkpoint are marked as "failed".
     */
    private function recoverStuckPlaybookSteps(): int
    {
        $heartbeatStale = now()->subMinutes(2);
        $noHeartbeatStale = now()->subMinutes(5);

        $stuckSteps = PlaybookStep::withoutGlobalScopes()
            ->where('status', 'running')
            ->where(function ($query) use ($heartbeatStale, $noHeartbeatStale) {
                $query
                    // Has heartbeat but it's stale
                    ->where(function ($q) use ($heartbeatStale) {
                        $q->whereNotNull('last_heartbeat_at')
                            ->where('last_heartbeat_at', '<', $heartbeatStale);
                    })
                    // No heartbeat and started long ago
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
                // Has checkpoint — reset to pending for retry
                $step->update([
                    'status' => 'pending',
                    'worker_id' => null,
                ]);

                $checkpointManager->writeCheckpoint($step->id, [
                    'phase' => 'recovered',
                    'recovered_at' => now()->toIso8601String(),
                    'previous_worker' => $step->worker_id,
                ]);

                // Re-dispatch the step job
                ExecutePlaybookStepJob::dispatch($step->id, $step->experiment_id, $step->experiment?->team_id)
                    ->onQueue('ai-calls');

                Log::info('RecoverStuckTasks: Re-dispatched stuck playbook step with checkpoint', [
                    'step_id' => $step->id,
                    'experiment_id' => $step->experiment_id,
                    'last_heartbeat_at' => $step->last_heartbeat_at,
                ]);
            } else {
                // No checkpoint — mark as failed
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

    private function transitionExperiment(string $experimentId, ExperimentStatus $toState, string $reason): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
        if (! $experiment || $experiment->status !== ExperimentStatus::Building) {
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
