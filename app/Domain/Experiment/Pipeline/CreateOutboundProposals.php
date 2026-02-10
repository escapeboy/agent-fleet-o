<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Approval\Actions\CreateApprovalRequestAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;

class CreateOutboundProposals extends BaseStageJob
{
    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::AwaitingApproval;
    }

    protected function stageType(): StageType
    {
        return StageType::Executing;
    }

    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        $createApproval = app(CreateApprovalRequestAction::class);

        // Get the building stage output for artifact/channel info
        $buildingStage = $experiment->stages()
            ->where('stage', StageType::Building)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $planningStage = $experiment->stages()
            ->where('stage', StageType::Planning)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $plan = $planningStage?->output_snapshot ?? [];
        $channels = $plan['outbound_channels'] ?? [
            ['channel' => 'email', 'target_description' => 'test@example.com'],
        ];

        $artifacts = $experiment->artifacts()
            ->where('metadata->iteration', $experiment->current_iteration)
            ->orWhere(function ($q) use ($experiment) {
                $q->where('experiment_id', $experiment->id);
            })
            ->get();

        $batchId = (string) \Illuminate\Support\Str::uuid();
        $proposals = [];

        foreach ($channels as $index => $channelSpec) {
            $channel = OutboundChannel::tryFrom($channelSpec['channel'] ?? 'email') ?? OutboundChannel::Email;
            $artifact = $artifacts->first();

            $proposal = OutboundProposal::withoutGlobalScopes()->create([
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'channel' => $channel,
                'target' => ['description' => $channelSpec['target_description'] ?? 'unknown'],
                'content' => [
                    'type' => 'experiment_summary',
                    'subject' => "Experiment Complete: {$experiment->title}",
                    'experiment_id' => $experiment->id,
                    'artifact_count' => $artifacts->count(),
                    'artifact_names' => $artifacts->pluck('name', 'type')->toArray(),
                    'thesis' => $experiment->thesis,
                    'iteration' => $experiment->current_iteration,
                ],
                'risk_score' => 0.5,
                'status' => OutboundProposalStatus::PendingApproval,
                'batch_index' => $index,
                'batch_id' => $batchId,
            ]);

            $proposals[] = $proposal;
        }

        // Create a single approval request for the batch
        $createApproval->execute(
            experiment: $experiment,
            outboundProposal: $proposals[0] ?? null,
            context: [
                'batch_id' => $batchId,
                'proposal_count' => count($proposals),
                'channels' => array_column($channels, 'channel'),
            ],
        );

        $stage->update([
            'output_snapshot' => [
                'batch_id' => $batchId,
                'proposal_count' => count($proposals),
            ],
        ]);

        // No transition here — awaiting_approval is a human gate
    }
}
