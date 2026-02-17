<?php

namespace App\Domain\Project\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Project\Services\DependencyResolver;
use Illuminate\Support\Facades\DB;

class TriggerProjectRunAction
{
    public function __construct(
        private CreateExperimentAction $createExperiment,
        private TransitionExperimentAction $transitionExperiment,
        private DependencyResolver $dependencyResolver,
    ) {}

    public function execute(Project $project, string $trigger = 'manual', ?array $inputData = null, ?array $scheduleOverrides = null): ProjectRun
    {
        return DB::transaction(function () use ($project, $trigger, $inputData, $scheduleOverrides) {
            $project = Project::withoutGlobalScopes()->lockForUpdate()->findOrFail($project->id);

            // Merge schedule overrides (from ProjectSchedule.overrides) if triggered by schedule
            $overrides = $scheduleOverrides ?? [];
            if ($trigger === 'schedule' && $project->schedule?->overrides) {
                $overrides = array_merge($project->schedule->overrides, $overrides);
            }

            $runNumber = ($project->total_runs ?? 0) + 1;

            // Apply budget cap override
            $budgetCap = $overrides['budget_cap_override']
                ?? $project->budget_config['per_run_cap']
                ?? 10000;

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
                title: $project->title.' — Run #'.$runNumber,
                thesis: $project->goal ?? $project->description ?? $project->title,
                track: 'growth',
                budgetCapCredits: $budgetCap,
                teamId: $project->team_id,
                workflowId: $project->workflow_id,
            );

            // Inject project execution mode + overrides + dependencies into experiment constraints
            $extraConstraints = [];

            // Execution mode: override > project default
            $executionMode = $overrides['execution_mode'] ?? $project->execution_mode?->value;
            if ($executionMode) {
                $extraConstraints['execution_mode'] = $executionMode;
            }

            // LLM model override from schedule
            if (! empty($overrides['model_override'])) {
                $extraConstraints['llm'] = [
                    'model' => $overrides['model_override'],
                ];
            }

            // Store schedule overrides for downstream reference
            if (! empty($overrides)) {
                $extraConstraints['schedule_overrides'] = $overrides;
            }

            $dependencyContext = $this->dependencyResolver->resolve($project);
            if (! empty($dependencyContext)) {
                $extraConstraints['dependency_context'] = $dependencyContext;
            }

            if (! empty($extraConstraints)) {
                $experiment->update([
                    'constraints' => array_merge($experiment->constraints ?? [], $extraConstraints),
                ]);
            }

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
            // Projects have a defined goal/task — skip scoring (which is for signal-driven experiments).
            // If the project has a workflow, skip directly to Executing (the workflow IS the plan).
            // Otherwise, go to Planning so the AI can build an execution plan.
            $targetState = $project->workflow_id
                ? ExperimentStatus::Executing
                : ExperimentStatus::Planning;

            $this->transitionExperiment->execute(
                experiment: $experiment,
                toState: $targetState,
                reason: "Triggered by project run #{$runNumber} ({$trigger})",
            );

            return $run;
        });
    }
}
