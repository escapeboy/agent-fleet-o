<?php

namespace App\Domain\Experiment\Listeners;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Mail\SentryFixPrOpenedMail;
use App\Domain\Signal\Models\Signal;
use App\Models\Artifact;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * On a Sentry-watchdog debug experiment's Building → AwaitingApproval
 * transition (fired by ExperimentCompleteBuildingTool when the agent finishes),
 * emails the configured operator that a fix PR has been opened.
 *
 * Filter chain (all must pass — otherwise the listener no-ops):
 *   1. toState === AwaitingApproval (the canonical transition that
 *      experiment_complete_building targets — see that tool's source for
 *      the contract)
 *   2. experiment.track === Debug
 *   3. experiment originated from a Sentry signal (Signal with
 *      experiment_id = experiment.id and source_identifier = 'sentry')
 *   4. a PR URL is reachable — either in the Building stage's output_snapshot
 *      or matchable from the experiment artifacts / thesis
 *
 * Recipient: sentry_watchdog.digest_email when set, else the team owner.
 */
class SendSentryFixPrOpenedEmailListener
{
    public function handle(ExperimentTransitioned $event): void
    {
        if ($event->toState !== ExperimentStatus::AwaitingApproval) {
            return;
        }

        $experiment = $event->experiment;

        if ($experiment->track !== ExperimentTrack::Debug) {
            return;
        }

        $signal = Signal::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('source_identifier', 'sentry')
            ->first();

        if ($signal === null) {
            return;
        }

        $prUrl = $this->extractPrUrl($experiment);
        if ($prUrl === null) {
            return;
        }

        $recipient = $this->resolveRecipient($experiment);
        if ($recipient === null) {
            Log::info('SendSentryFixPrOpenedEmailListener: no recipient resolvable', [
                'experiment_id' => $experiment->id,
                'team_id' => $experiment->team_id,
            ]);

            return;
        }

        $payload = $signal->payload ?? [];
        $sentryPayload = (isset($payload['payload']) && is_array($payload['payload']))
            ? $payload['payload']
            : $payload;

        $title = (string) ($sentryPayload['title'] ?? $experiment->title ?? 'Sentry issue');
        $sentryPermalink = (string) ($payload['sentry_permalink'] ?? $sentryPayload['permalink'] ?? '');
        $targetRepo = (string) ($payload['target_repository'] ?? '');
        $summary = $this->resolveSummary($experiment);

        Mail::to($recipient)->send(new SentryFixPrOpenedMail(
            title: $title,
            sentryPermalink: $sentryPermalink,
            prUrl: $prUrl,
            summary: $summary,
            targetRepo: $targetRepo,
        ));
    }

    /**
     * Pull PR URL from the building stage's output_snapshot first (that's where
     * experiment_complete_building stores `pr_urls`); fall back to any
     * github.com/.../pull/<n> URL present on an artifact or in the thesis.
     */
    private function extractPrUrl(Experiment $experiment): ?string
    {
        $buildingStage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Building)
            ->latest('completed_at')
            ->first();

        if ($buildingStage !== null) {
            $output = $buildingStage->output_snapshot ?? [];
            $prUrls = is_array($output['pr_urls'] ?? null) ? $output['pr_urls'] : [];
            foreach ($prUrls as $url) {
                if (is_string($url) && $this->looksLikePrUrl($url)) {
                    return $url;
                }
            }
        }

        $artifacts = Artifact::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->get();
        foreach ($artifacts as $artifact) {
            $found = $this->matchPrUrl((string) ($artifact->name ?? ''))
                ?? $this->matchPrUrl(json_encode($artifact->metadata ?? []) ?: '');
            if ($found !== null) {
                return $found;
            }
        }

        return $this->matchPrUrl((string) ($experiment->thesis ?? ''));
    }

    private function looksLikePrUrl(string $url): bool
    {
        return (bool) preg_match('#^https?://github\.com/[^/]+/[^/]+/pull/\d+#', $url);
    }

    private function matchPrUrl(string $haystack): ?string
    {
        if ($haystack === '') {
            return null;
        }

        if (preg_match('#https?://github\.com/[^/\s]+/[^/\s]+/pull/\d+#', $haystack, $m)) {
            return $m[0];
        }

        return null;
    }

    private function resolveRecipient(Experiment $experiment): ?string
    {
        $configured = config('sentry_watchdog.digest_email');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $ownerId = Team::ownerIdFor($experiment->team_id);
        if ($ownerId === null) {
            return null;
        }

        return User::find($ownerId)?->email;
    }

    private function resolveSummary(Experiment $experiment): string
    {
        $stage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Building)
            ->latest('completed_at')
            ->first();

        if ($stage === null) {
            return '';
        }

        $output = $stage->output_snapshot ?? [];
        $summary = $output['summary'] ?? null;

        return is_string($summary) ? $summary : '';
    }
}
