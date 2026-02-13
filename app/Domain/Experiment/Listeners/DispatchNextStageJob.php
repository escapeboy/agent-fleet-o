<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Pipeline\CollectMetrics;
use App\Domain\Experiment\Pipeline\CreateOutboundProposals;
use App\Domain\Experiment\Pipeline\ExecuteOutbound;
use App\Domain\Experiment\Pipeline\PlaybookExecutor;
use App\Domain\Experiment\Pipeline\RunBuildingStage;
use App\Domain\Experiment\Pipeline\RunEvaluationStage;
use App\Domain\Experiment\Pipeline\RunPlanningStage;
use App\Domain\Experiment\Pipeline\RunScoringStage;
use App\Domain\Workflow\Services\WorkflowGraphExecutor;
use Illuminate\Support\Facades\Log;

class DispatchNextStageJob
{
    /**
     * Map: new experiment state => job class to dispatch.
     * States not in this map are human gates (awaiting_approval) or terminal.
     */
    private const STATE_JOB_MAP = [
        'scoring' => RunScoringStage::class,
        'planning' => RunPlanningStage::class,
        'building' => RunBuildingStage::class,
        'awaiting_approval' => CreateOutboundProposals::class,
        'executing' => ExecuteOutbound::class,
        'collecting_metrics' => CollectMetrics::class,
        'evaluating' => RunEvaluationStage::class,
    ];

    public function handle(ExperimentTransitioned $event): void
    {
        $newState = $event->toState->value;
        $experiment = $event->experiment;

        // Workflow/Playbook mode: if experiment enters Executing and has playbook steps,
        // use graph executor for workflow experiments or flat executor for legacy playbooks
        if ($newState === 'executing' && $experiment->playbookSteps()->exists()) {
            if ($experiment->hasWorkflow()) {
                Log::info('DispatchNextStageJob: Dispatching WorkflowGraphExecutor', [
                    'experiment_id' => $experiment->id,
                    'workflow_id' => $experiment->workflow_id,
                    'steps_count' => $experiment->playbookSteps()->count(),
                ]);

                app(WorkflowGraphExecutor::class)->execute($experiment);
            } else {
                Log::info('DispatchNextStageJob: Dispatching PlaybookExecutor (legacy)', [
                    'experiment_id' => $experiment->id,
                    'steps_count' => $experiment->playbookSteps()->count(),
                ]);

                app(PlaybookExecutor::class)->execute($experiment);
            }

            return;
        }

        // Iterating: workflow experiments re-execute, non-workflow re-plan
        if ($newState === 'iterating') {
            if ($experiment->hasWorkflow()) {
                Log::info('DispatchNextStageJob: Iterating → re-executing workflow', [
                    'experiment_id' => $experiment->id,
                    'iteration' => $experiment->current_iteration,
                ]);

                // Reset all workflow steps for re-execution
                $experiment->playbookSteps()->update([
                    'status' => 'pending',
                    'output' => null,
                    'error_message' => null,
                    'duration_ms' => null,
                    'cost_credits' => null,
                    'started_at' => null,
                    'completed_at' => null,
                ]);

                app(TransitionExperimentAction::class)->execute(
                    experiment: $experiment,
                    toState: ExperimentStatus::Executing,
                    reason: "Iteration {$experiment->current_iteration}: re-executing workflow",
                );
            } else {
                Log::info('DispatchNextStageJob: Iterating → transitioning to planning', [
                    'experiment_id' => $experiment->id,
                    'iteration' => $experiment->current_iteration,
                ]);

                app(TransitionExperimentAction::class)->execute(
                    experiment: $experiment,
                    toState: ExperimentStatus::Planning,
                    reason: "Iteration {$experiment->current_iteration}: re-entering planning",
                );
            }

            return;
        }

        $jobClass = self::STATE_JOB_MAP[$newState] ?? null;

        if (! $jobClass) {
            Log::debug('DispatchNextStageJob: No job mapped for state', [
                'experiment_id' => $experiment->id,
                'to_state' => $newState,
            ]);

            return;
        }

        Log::info('DispatchNextStageJob: Dispatching', [
            'experiment_id' => $experiment->id,
            'to_state' => $newState,
            'job' => class_basename($jobClass),
        ]);

        $jobClass::dispatch($experiment->id);
    }
}
