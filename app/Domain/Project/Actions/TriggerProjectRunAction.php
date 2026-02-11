<?php

namespace App\Domain\Project\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use Illuminate\Support\Facades\DB;

class TriggerProjectRunAction
{
    public function __construct(
        private CreateExperimentAction $createExperiment,
        private TransitionExperimentAction $transitionExperiment,
    ) {}

    public function execute(Project $project, string $trigger = 'manual', ?array $inputData = null): ProjectRun
    {
        return DB::transaction(function () use ($project, $trigger, $inputData) {
            $project = Project::withoutGlobalScopes()->lockForUpdate()->findOrFail($project->id);

            $runNumber = ($project->total_runs ?? 0) + 1;

            $run = ProjectRun::create([
                'project_id' => $project->id,
                'run_number' => $runNumber,
                'status' => ProjectRunStatus::Pending,
                'trigger' => $trigger,
                'input_data' => $inputData,
            ]);

            // Create experiment for this run
            $experiment = $this->createExperiment->execute(
                userId: $project->user_id,
                title: $project->title . ' — Run #' . $runNumber,
                thesis: $project->goal ?? $project->description ?? $project->title,
                track: 'outbound',
                budgetCapCredits: $project->budget_config['per_run_cap'] ?? 10000,
                teamId: $project->team_id,
                workflowId: $project->workflow_id,
            );

            $run->update([
                'experiment_id' => $experiment->id,
                'status' => ProjectRunStatus::Running,
                'started_at' => now(),
            ]);

            $project->update([
                'total_runs' => $runNumber,
                'last_run_at' => now(),
            ]);

            // Start the experiment pipeline
            $this->transitionExperiment->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Scoring,
                reason: "Triggered by project run #{$runNumber} ({$trigger})",
            );

            return $run;
        });
    }
}
