<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentTask;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class RunBuildingStage extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Building;
    }

    protected function stageType(): StageType
    {
        return StageType::Building;
    }

    /**
     * Override BaseStageJob::handle() entirely to dispatch a Bus::batch
     * instead of building artifacts inline. The stage stays Running while
     * individual BuildArtifactJob instances run in parallel.
     */
    public function handle(): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            Log::warning('RunBuildingStage: Experiment not found', [
                'experiment_id' => $this->experimentId,
            ]);
            return;
        }

        if ($experiment->status !== $this->expectedState()) {
            Log::info('RunBuildingStage: State guard — not in Building state', [
                'experiment_id' => $experiment->id,
                'actual' => $experiment->status->value,
            ]);
            return;
        }

        $stage = $this->findOrCreateStage($experiment);

        // Idempotency: if this stage already has a batch_id, it was already dispatched.
        // This prevents duplicates when RunBuildingStage is retried.
        if (! empty($stage->output_snapshot['batch_id'])) {
            Log::info('RunBuildingStage: Batch already dispatched for this stage, skipping', [
                'experiment_id' => $experiment->id,
                'batch_id' => $stage->output_snapshot['batch_id'],
            ]);
            return;
        }

        $stage->update([
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        // Get plan from planning stage
        $planningStage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Planning)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $plan = $planningStage?->output_snapshot ?? [];
        $artifactsToBuild = $plan['artifacts_to_build']
            ?? $plan['plan']['artifacts_to_build']
            ?? [['type' => 'email_template', 'name' => 'outreach_email', 'description' => 'Outreach email for experiment']];

        // Create ExperimentTask records
        $tasks = [];
        foreach ($artifactsToBuild as $index => $artifactSpec) {
            $tasks[] = ExperimentTask::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'stage' => 'building',
                'name' => $artifactSpec['name'] ?? "artifact_{$index}",
                'description' => $artifactSpec['description'] ?? null,
                'type' => $artifactSpec['type'] ?? 'unknown',
                'status' => ExperimentTaskStatus::Pending,
                'sort_order' => $index,
                'input_data' => [
                    'artifact_spec' => $artifactSpec,
                    'plan' => $plan,
                ],
            ]);
        }

        // Build job instances
        $jobs = array_map(
            fn (ExperimentTask $task) => new BuildArtifactJob(
                experimentId: $experiment->id,
                taskId: $task->id,
                teamId: $experiment->team_id,
            ),
            $tasks
        );

        // Capture IDs for closures (closures can't use $this in serialized callbacks)
        $experimentId = $experiment->id;
        $stageId = $stage->id;

        $batch = Bus::batch([$jobs])
            ->name("building:{$experimentId}")
            ->onQueue('ai-calls')
            ->then(function () use ($experimentId, $stageId) {
                // All jobs succeeded
                $stage = ExperimentStage::withoutGlobalScopes()->find($stageId);
                if ($stage && $stage->status === StageStatus::Running) {
                    $builtArtifacts = ExperimentTask::withoutGlobalScopes()
                        ->where('experiment_id', $experimentId)
                        ->where('stage', 'building')
                        ->where('status', ExperimentTaskStatus::Completed)
                        ->get()
                        ->map(fn ($t) => $t->output_data)
                        ->filter()
                        ->values()
                        ->toArray();

                    $stage->update([
                        'status' => StageStatus::Completed,
                        'completed_at' => now(),
                        'duration_ms' => $stage->started_at ? (int) now()->diffInMilliseconds($stage->started_at) : null,
                        'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                            'artifacts_built' => $builtArtifacts,
                        ]),
                    ]);

                    $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
                    if ($experiment && $experiment->status === ExperimentStatus::Building) {
                        app(TransitionExperimentAction::class)->execute(
                            experiment: $experiment,
                            toState: ExperimentStatus::AwaitingApproval,
                            reason: 'All artifacts built, awaiting approval',
                        );
                    }
                }
            })
            ->catch(function (\Throwable $e) use ($experimentId) {
                Log::warning('RunBuildingStage: Batch has failures', [
                    'experiment_id' => $experimentId,
                    'error' => $e->getMessage(),
                ]);
            })
            ->finally(function () use ($experimentId, $stageId) {
                $stage = ExperimentStage::withoutGlobalScopes()->find($stageId);

                // If stage is still Running after all jobs finished (meaning then() didn't fire = there were failures)
                if ($stage && $stage->status === StageStatus::Running) {
                    $failedCount = ExperimentTask::withoutGlobalScopes()
                        ->where('experiment_id', $experimentId)
                        ->where('stage', 'building')
                        ->where('status', ExperimentTaskStatus::Failed)
                        ->count();

                    if ($failedCount > 0) {
                        // Skip remaining pending tasks
                        ExperimentTask::withoutGlobalScopes()
                            ->where('experiment_id', $experimentId)
                            ->where('stage', 'building')
                            ->whereIn('status', [ExperimentTaskStatus::Pending, ExperimentTaskStatus::Queued])
                            ->update([
                                'status' => ExperimentTaskStatus::Skipped,
                                'error' => 'Batch aborted — other tasks failed',
                                'completed_at' => now(),
                            ]);

                        $stage->update([
                            'status' => StageStatus::Failed,
                            'completed_at' => now(),
                            'duration_ms' => $stage->started_at ? (int) now()->diffInMilliseconds($stage->started_at) : null,
                            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                                'error' => "{$failedCount} artifact(s) failed to build",
                            ]),
                        ]);

                        $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
                        if ($experiment && $experiment->status === ExperimentStatus::Building) {
                            try {
                                app(TransitionExperimentAction::class)->execute(
                                    experiment: $experiment,
                                    toState: ExperimentStatus::BuildingFailed,
                                    reason: "{$failedCount} artifact(s) failed to build",
                                );
                            } catch (\Throwable $e) {
                                Log::error('RunBuildingStage: Failed to transition to BuildingFailed', [
                                    'experiment_id' => $experimentId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }
            })
            ->dispatch();

        // Store batch_id FIRST on the stage (before any jobs can complete)
        $batchId = $batch->id;

        $stage->update([
            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                'batch_id' => $batchId,
                'total_tasks' => count($tasks),
            ]),
        ]);

        // Update all tasks with batch_id (regardless of current status — fast jobs may already be running)
        ExperimentTask::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', 'building')
            ->whereNull('batch_id')
            ->update(['batch_id' => $batchId]);

        Log::info('RunBuildingStage: Dispatched artifact batch', [
            'experiment_id' => $experiment->id,
            'batch_id' => $batchId,
            'total_tasks' => count($tasks),
        ]);
    }

    /**
     * process() is required by BaseStageJob but won't be called since
     * we override handle() entirely.
     */
    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        // Not used — handle() is overridden
    }
}
