<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Outbound\Enums\OutboundActionStatus;

class CollectMetrics extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('metrics');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::CollectingMetrics;
    }

    protected function stageType(): StageType
    {
        return StageType::CollectingMetrics;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $transition = app(TransitionExperimentAction::class);

        // Collect metrics from outbound actions
        $outboundActions = $experiment->outboundProposals()
            ->with('outboundActions')
            ->get()
            ->flatMap->outboundActions;

        $metrics = [];

        foreach ($outboundActions as $action) {
            // Record delivery metric
            $delivered = $action->status === OutboundActionStatus::Sent;

            Metric::withoutGlobalScopes()->create([
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'outbound_action_id' => $action->id,
                'type' => 'delivery',
                'value' => $delivered ? 1.0 : 0.0,
                'source' => 'outbound_connector',
                'metadata' => [
                    'channel' => $action->outboundProposal->channel->value ?? 'unknown',
                    'status' => $action->status->value,
                ],
                'occurred_at' => $action->sent_at ?? now(),
                'recorded_at' => now(),
            ]);

            // Record dummy engagement metric (simulated for Phase 3)
            if ($delivered) {
                $engagement = mt_rand(0, 100) / 100;

                Metric::withoutGlobalScopes()->create([
                    'experiment_id' => $experiment->id,
                    'team_id' => $experiment->team_id,
                    'outbound_action_id' => $action->id,
                    'type' => 'engagement',
                    'value' => $engagement,
                    'source' => 'simulated',
                    'metadata' => ['simulated' => true],
                    'occurred_at' => now(),
                    'recorded_at' => now(),
                ]);

                $metrics[] = ['type' => 'engagement', 'value' => $engagement];
            }

            $metrics[] = ['type' => 'delivery', 'value' => $delivered ? 1.0 : 0.0];
        }

        // Collect workflow step metrics (for workflow/playbook experiments)
        $completedSteps = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('status', 'completed')
            ->get();

        if ($completedSteps->isNotEmpty()) {
            $totalSteps = PlaybookStep::where('experiment_id', $experiment->id)->count();

            foreach ($completedSteps as $step) {
                Metric::withoutGlobalScopes()->create([
                    'experiment_id' => $experiment->id,
                    'team_id' => $experiment->team_id,
                    'type' => 'step_completion',
                    'value' => 1.0,
                    'source' => 'workflow_step',
                    'metadata' => [
                        'step_order' => $step->order,
                        'duration_ms' => $step->duration_ms,
                        'cost_credits' => $step->cost_credits,
                    ],
                    'occurred_at' => $step->completed_at ?? now(),
                    'recorded_at' => now(),
                ]);

                $metrics[] = [
                    'type' => 'step_completion',
                    'step_order' => $step->order,
                    'duration_ms' => $step->duration_ms,
                ];
            }

            // Aggregate workflow summary metric
            Metric::withoutGlobalScopes()->create([
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'type' => 'workflow_summary',
                'value' => $completedSteps->count() / max(1, $totalSteps),
                'source' => 'workflow_aggregate',
                'metadata' => [
                    'completed' => $completedSteps->count(),
                    'total' => $totalSteps,
                    'total_duration_ms' => $completedSteps->sum('duration_ms'),
                    'total_cost_credits' => $completedSteps->sum('cost_credits'),
                ],
                'occurred_at' => now(),
                'recorded_at' => now(),
            ]);

            $metrics[] = [
                'type' => 'workflow_summary',
                'completed' => $completedSteps->count(),
                'total' => $totalSteps,
            ];
        }

        $stage->update([
            'output_snapshot' => [
                'metrics_collected' => count($metrics),
                'summary' => $metrics,
            ],
        ]);

        $transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Evaluating,
            reason: 'Metrics collected',
        );
    }
}
