<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * When a GitHub Pull Request opened by an autonomous Sentry-watchdog fix gets
 * merged, resolve the corresponding Sentry issue and close the originating
 * Signal. The GitHub analogue of CloseBugReportOnPrMergeListener for
 * Sentry-delegated experiments (component G2 of the Sentry Watchdog sprint).
 *
 * Correlation order:
 *   1. `git_pull_requests` row matching the merged PR URL → experiment by
 *      `agent_id` → the sentry Signal carrying `experiment_id`.
 *   2. `signal_comments.body` substring scan for the merged PR URL within the
 *      last 30 days (fallback for stateless PR-create tooling).
 *
 * Idempotent: a `payload['sentry_resolved']` breadcrumb prevents a re-fired
 * event from double-resolving the issue or double-posting the closure note.
 * Never throws — every external call is wrapped; failures are logged.
 */
class CloseSentryIssueOnPrMergeListener
{
    public function __construct(
        private readonly ExecuteIntegrationActionAction $executeIntegration,
        private readonly UpdateSignalStatusAction $updateStatus,
    ) {}

    public function handle(SignalIngested $event): void
    {
        $signal = $event->signal;

        if ($signal->source_type !== 'github') {
            return;
        }

        $tags = (array) ($signal->tags ?? []);
        if (! in_array('merged', $tags, true)) {
            return;
        }

        $prUrl = $this->extractPrUrl((array) ($signal->payload ?? []));
        if ($prUrl === null) {
            Log::warning('CloseSentryIssueOnPrMergeListener: merged-PR signal without resolvable PR URL', [
                'signal_id' => $signal->id,
            ]);

            return;
        }

        $sentrySignal = $this->correlate($prUrl, $signal->team_id);
        if ($sentrySignal === null) {
            Log::info('CloseSentryIssueOnPrMergeListener: no originating sentry signal for merged PR', [
                'merged_signal_id' => $signal->id,
                'pr_url' => $prUrl,
            ]);

            return;
        }

        // Idempotency guard — already closed (or already resolved this PR).
        if ($sentrySignal->status->isTerminal()) {
            return;
        }

        // Only resolve signals that were actually delegated for an autonomous
        // fix and are still in a status that can transition to Resolved. Guards
        // the comment-body fallback against mis-correlating an investigate-only
        // signal (which would otherwise resolve the Sentry issue remotely while
        // the transition map rejects the local status change).
        $resolvable = [
            SignalStatus::DelegatedToAgent,
            SignalStatus::AgentFixing,
            SignalStatus::Review,
            SignalStatus::InProgress,
        ];
        if ($sentrySignal->experiment_id === null
            || ! in_array($sentrySignal->status, $resolvable, true)) {
            Log::info('CloseSentryIssueOnPrMergeListener: correlated sentry signal not in a resolvable state', [
                'sentry_signal_id' => $sentrySignal->id,
                'status' => $sentrySignal->status->value,
            ]);

            return;
        }

        $payload = (array) ($sentrySignal->payload ?? []);
        if (($payload['sentry_resolved']['pr_url'] ?? null) === $prUrl) {
            return;
        }

        $issueId = $payload['sentry_issue_id'] ?? null;
        if (! is_string($issueId) || $issueId === '') {
            Log::warning('CloseSentryIssueOnPrMergeListener: sentry signal missing sentry_issue_id', [
                'sentry_signal_id' => $sentrySignal->id,
            ]);

            return;
        }

        $integration = $this->resolveSentryIntegration($sentrySignal->team_id);
        if ($integration === null) {
            Log::warning('CloseSentryIssueOnPrMergeListener: no active Sentry integration for team', [
                'team_id' => $sentrySignal->team_id,
                'sentry_signal_id' => $sentrySignal->id,
            ]);

            return;
        }

        if (! $this->resolveSentryIssue($integration, $issueId)) {
            return;
        }

        $this->postClosureNote($integration, $issueId, $prUrl);
        $this->stampResolution($sentrySignal, $issueId, $prUrl);

        if ($sentrySignal->status === SignalStatus::Resolved) {
            return;
        }

        try {
            $this->updateStatus->execute(
                signal: $sentrySignal,
                newStatus: SignalStatus::Resolved,
            );
        } catch (\Throwable $e) {
            Log::warning('CloseSentryIssueOnPrMergeListener: status transition to Resolved failed', [
                'sentry_signal_id' => $sentrySignal->id,
                'from' => $sentrySignal->status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractPrUrl(array $payload): ?string
    {
        $url = $payload['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    private function correlate(string $prUrl, ?string $teamId): ?Signal
    {
        $pull = GitPullRequest::query()
            ->where('pr_url', $prUrl)
            ->latest('updated_at')
            ->first();

        if ($pull !== null) {
            $experiment = Experiment::withoutGlobalScopes()
                ->where('agent_id', $pull->agent_id)
                ->where('team_id', $teamId)
                ->latest('created_at')
                ->first();

            if ($experiment !== null) {
                $signal = Signal::withoutGlobalScopes()
                    ->where('experiment_id', $experiment->id)
                    ->where('source_type', 'sentry')
                    ->first();

                if ($signal !== null) {
                    return $signal;
                }
            }
        }

        // Fallback: agents that post the PR URL in a comment leave a
        // discoverable breadcrumb on the originating signal.
        $commentRow = SignalComment::query()
            ->where('team_id', $teamId)
            ->where('author_type', 'agent')
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->where('body', 'like', '%'.$prUrl.'%')
            ->latest('created_at')
            ->first();

        if ($commentRow === null) {
            return null;
        }

        return Signal::withoutGlobalScopes()
            ->where('id', $commentRow->signal_id)
            ->where('source_type', 'sentry')
            ->first();
    }

    private function resolveSentryIntegration(?string $teamId): ?Integration
    {
        return Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('driver', 'sentry')
            ->where('status', IntegrationStatus::Active)
            ->latest('updated_at')
            ->first();
    }

    private function resolveSentryIssue(Integration $integration, string $issueId): bool
    {
        try {
            $this->executeIntegration->execute($integration, 'resolve_issue', ['issue_id' => $issueId]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('CloseSentryIssueOnPrMergeListener: resolve_issue failed', [
                'integration_id' => $integration->id,
                'sentry_issue_id' => $issueId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function postClosureNote(Integration $integration, string $issueId, string $prUrl): void
    {
        try {
            $this->executeIntegration->execute($integration, 'create_note', [
                'issue_id' => $issueId,
                'text' => 'Resolved by FleetQ Sentry Watchdog — autonomous fix merged: '.$prUrl,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CloseSentryIssueOnPrMergeListener: create_note failed', [
                'integration_id' => $integration->id,
                'sentry_issue_id' => $issueId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function stampResolution(Signal $signal, string $issueId, string $prUrl): void
    {
        $payload = (array) ($signal->payload ?? []);
        $payload['sentry_resolved'] = [
            'issue_id' => $issueId,
            'pr_url' => $prUrl,
            'resolved_at' => Carbon::now()->toIso8601String(),
        ];

        $signal->update(['payload' => $payload]);
    }
}
