<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
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

                Metric::create([
                    'experiment_id' => $experiment->id,
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
