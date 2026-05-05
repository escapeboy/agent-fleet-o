<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Approval\Actions\CreateApprovalRequestAction;
use App\Domain\Approval\Enums\ApprovalMode;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectRun;
use App\Domain\Signal\Models\Signal;
use App\Domain\Website\Models\Website;
use Illuminate\Support\Str;

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
        // web_build experiments have no outbound proposals — create a website review request instead
        if ($experiment->track === ExperimentTrack::WebBuild) {
            $this->processWebBuildApproval($experiment, $stage);

            return;
        }

        $autoApprove = $experiment->constraints['auto_approve'] ?? false;

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

        // Filter channels through project's allowed outbound channels
        $channels = $this->filterAllowedChannels($channels, $experiment);

        $artifacts = $experiment->artifacts()
            ->where('metadata->iteration', $experiment->current_iteration)
            ->orWhere(function ($q) use ($experiment) {
                $q->where('experiment_id', $experiment->id);
            })
            ->get();

        // Resolve signal contact data from the triggering signal (if any)
        $signalContact = $this->resolveSignalContact($experiment);

        $batchId = (string) Str::uuid();
        $proposals = [];

        foreach ($channels as $index => $channelSpec) {
            $channel = OutboundChannel::tryFrom($channelSpec['channel'] ?? 'email') ?? OutboundChannel::Email;

            $target = $this->buildTarget($channel, $channelSpec, $signalContact, $experiment);

            $proposal = OutboundProposal::withoutGlobalScopes()->create([
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
                'channel' => $channel,
                'target' => $target,
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
                'status' => $autoApprove ? OutboundProposalStatus::Approved : OutboundProposalStatus::PendingApproval,
                'batch_index' => $index,
                'batch_id' => $batchId,
            ]);

            $proposals[] = $proposal;
        }

        // Create approval request (auto-approved or pending)
        $approvalMode = $autoApprove
            ? ApprovalMode::InLoop
            : (ApprovalMode::tryFrom($experiment->constraints['approval_mode'] ?? 'in_loop') ?? ApprovalMode::InLoop);
        $interventionWindowSeconds = (! $autoApprove && $approvalMode === ApprovalMode::OnLoop)
            ? ($experiment->constraints['intervention_window_seconds'] ?? null)
            : null;

        $createApproval = app(CreateApprovalRequestAction::class);
        $approvalRequest = $createApproval->execute(
            experiment: $experiment,
            outboundProposal: $proposals[0] ?? null,
            context: [
                'batch_id' => $batchId,
                'proposal_count' => count($proposals),
                'channels' => array_column($channels, 'channel'),
                'auto_approved' => $autoApprove,
            ],
            mode: $approvalMode,
            interventionWindowSeconds: $interventionWindowSeconds,
        );

        if ($autoApprove) {
            $approvalRequest->update([
                'status' => ApprovalStatus::Approved,
                'reviewed_at' => now(),
                'reviewed_by' => null,
                'reviewer_notes' => 'Auto-approved by experiment constraints',
            ]);
        }

        $stage->update([
            'output_snapshot' => [
                'batch_id' => $batchId,
                'proposal_count' => count($proposals),
                'auto_approved' => $autoApprove,
            ],
        ]);

        // Auto-approve: transition through Approved → Executing automatically
        if ($autoApprove) {
            $transitionAction = app(TransitionExperimentAction::class);
            $experiment = $transitionAction->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Approved,
                reason: 'Auto-approved by experiment constraints',
            );
            $transitionAction->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Executing,
                reason: 'Auto-approved — proceeding to execution',
            );
        }

        // When not auto-approved, no transition — awaiting_approval is a human gate
    }

    /**
     * Handle web_build experiments: create a website review ApprovalRequest
     * so the user can inspect the generated site before it is published.
     * No outbound proposals are created — there is nothing to send.
     */
    private function processWebBuildApproval(Experiment $experiment, ExperimentStage $stage): void
    {
        $buildingStage = $experiment->stages()
            ->where('stage', StageType::Building)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $websiteId = $buildingStage?->output_snapshot['website_id'] ?? null;
        $website = $websiteId ? Website::withoutGlobalScopes()->with('pages')->find($websiteId) : null;

        $publicUrl = $website ? url("/api/public/sites/{$website->slug}") : null;
        $adminUrl = $website ? route('websites.show', $website) : null;
        $pages = $website?->pages->map(fn ($p) => ['slug' => $p->slug, 'title' => $p->title])->toArray() ?? [];

        $createApproval = app(CreateApprovalRequestAction::class);
        $approvalRequest = $createApproval->execute(
            experiment: $experiment,
            outboundProposal: null,
            context: [
                'type' => 'website_review',
                'website_id' => $websiteId,
                'website_url' => $publicUrl,
                'admin_url' => $adminUrl,
                'pages' => $pages,
                'message' => 'Review the generated website before finalising.',
            ],
        );

        $autoApprove = $experiment->constraints['auto_approve'] ?? false;

        if ($autoApprove) {
            $approvalRequest->update([
                'status' => ApprovalStatus::Approved,
                'reviewed_at' => now(),
                'reviewed_by' => null,
                'reviewer_notes' => 'Auto-approved by experiment constraints',
            ]);

            $transitionAction = app(TransitionExperimentAction::class);
            $experiment = $transitionAction->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Approved,
                reason: 'Website auto-approved',
            );
            $transitionAction->execute(
                experiment: $experiment,
                toState: ExperimentStatus::Executing,
                reason: 'Website auto-approved — proceeding to execution',
            );
        }

        $stage->update([
            'output_snapshot' => [
                'type' => 'website_review',
                'website_id' => $websiteId,
                'website_url' => $publicUrl,
                'approval_request_id' => $approvalRequest->id,
                'auto_approved' => $autoApprove,
            ],
        ]);
    }

    /**
     * Resolve contact data from the signal that triggered this experiment.
     * Chain: Experiment → ProjectRun.signal_id → Signal.payload
     *
     * @return array{email?: string, name?: string, subject?: string, message_id?: string, source_type?: string}
     */
    private function resolveSignalContact(Experiment $experiment): array
    {
        $run = ProjectRun::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->whereNotNull('signal_id')
            ->first();

        if (! $run?->signal_id) {
            return [];
        }

        $signal = Signal::withoutGlobalScopes()
            ->where('id', $run->signal_id)
            ->where('team_id', $experiment->team_id)
            ->first();
        if (! $signal) {
            return [];
        }

        $payload = $signal->payload ?? [];

        return array_filter([
            'email' => $payload['from'] ?? $signal->source_identifier ?? null,
            'name' => $payload['from_name'] ?? null,
            'subject' => $payload['subject'] ?? $payload['title'] ?? null,
            'message_id' => $payload['message_id'] ?? null,
            'source_type' => $signal->source_type,
            'signal_id' => $signal->id,
        ]);
    }

    /**
     * Build the outbound target with real contact data when available.
     *
     * Email resolution priority:
     * 1. Signal contact email (from triggering signal's payload)
     * 2. Explicit email in channelSpec (from AI planning)
     * 3. Email extracted from target_description (if it contains @)
     * 4. Project delivery_config default_recipient
     */
    private function buildTarget(OutboundChannel $channel, array $channelSpec, array $signalContact, ?Experiment $experiment = null): array
    {
        $target = ['description' => $channelSpec['target_description'] ?? 'unknown'];

        if ($channel === OutboundChannel::Email) {
            $email = $signalContact['email']
                ?? $channelSpec['email']
                ?? $this->extractEmailFromDescription($channelSpec['target_description'] ?? '')
                ?? $this->resolveProjectDefaultRecipient($experiment)
                ?? null;

            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $target['email'] = $email;
            }

            if (! empty($signalContact['name'])) {
                $target['name'] = $signalContact['name'];
            }
            // Threading headers for proper reply chains
            if (! empty($signalContact['message_id'])) {
                $target['in_reply_to'] = $signalContact['message_id'];
                $target['references'] = $signalContact['message_id'];
            }
            if (! empty($signalContact['subject'])) {
                $target['reply_subject'] = $signalContact['subject'];
            }
        }

        return $target;
    }

    /**
     * Extract an email address from a freetext description string.
     */
    private function extractEmailFromDescription(string $description): ?string
    {
        if (preg_match('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $description, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Resolve default recipient from the project's delivery_config.
     */
    private function resolveProjectDefaultRecipient(?Experiment $experiment): ?string
    {
        if (! $experiment) {
            return null;
        }

        $run = ProjectRun::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->first();

        if (! $run?->project_id) {
            return null;
        }

        $project = Project::withoutGlobalScopes()
            ->where('id', $run->project_id)
            ->where('team_id', $experiment->team_id)
            ->first();

        return $project?->delivery_config['default_recipient'] ?? null;
    }

    /**
     * Filter AI-proposed channels through the project's allowed outbound channels.
     */
    private function filterAllowedChannels(array $channels, Experiment $experiment): array
    {
        $run = ProjectRun::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->first();

        if (! $run?->project_id) {
            return $channels;
        }

        $project = Project::withoutGlobalScopes()
            ->where('id', $run->project_id)
            ->where('team_id', $experiment->team_id)
            ->first();
        $deliveryConfig = $project?->delivery_config ?? [];
        $allowed = $deliveryConfig['allowed_outbound_channels'] ?? null;

        // No config = allow all (backward compat)
        if ($allowed === null || $allowed === []) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn (array $ch) => in_array($ch['channel'] ?? '', $allowed, true),
        ));
    }
}
