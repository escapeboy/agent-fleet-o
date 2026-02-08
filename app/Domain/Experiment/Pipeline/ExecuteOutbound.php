<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Outbound\Actions\SendOutboundAction;
use App\Domain\Outbound\Enums\OutboundProposalStatus;

class ExecuteOutbound extends BaseStageJob
{
    public function __construct(string $experimentId)
    {
        parent::__construct($experimentId);
        $this->onQueue('outbound');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Executing;
    }

    protected function stageType(): StageType
    {
        return StageType::Executing;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $sendAction = app(SendOutboundAction::class);
        $transition = app(TransitionExperimentAction::class);

        $proposals = $experiment->outboundProposals()
            ->where('status', OutboundProposalStatus::Approved)
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($proposals as $proposal) {
            if ($experiment->outbound_count >= $experiment->max_outbound_count) {
                break;
            }

            try {
                $sendAction->execute($proposal);
                $sent++;

                $experiment->increment('outbound_count');
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        $stage->update([
            'output_snapshot' => [
                'sent' => $sent,
                'failed' => $failed,
                'total_proposals' => $proposals->count(),
            ],
        ]);

        $transition->execute(
            experiment: $experiment,
            toState: ExperimentStatus::CollectingMetrics,
            reason: "Outbound complete: {$sent} sent, {$failed} failed",
        );
    }
}
