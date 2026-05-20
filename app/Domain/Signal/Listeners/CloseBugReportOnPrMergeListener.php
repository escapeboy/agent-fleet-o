<?php

namespace App\Domain\Signal\Listeners;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Actions\UpdateSignalStatusAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Models\BugReportProjectConfig;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * When a `pullrequest:fulfilled` (merged) signal is ingested from Bitbucket,
 * find the originating bug-report Signal whose agent opened that PR, post a
 * closure comment, and transition the bug-report to Resolved.
 *
 * Correlation order:
 *   1. `git_pull_requests` row matching the merged PR URL (canonical when
 *      the agent's PR-create tool persisted a row).
 *   2. `signal_comments.body` substring scan for the merged PR URL within
 *      the last 30 days (fallback for tools that don't persist — e.g.
 *      `bitbucket_pr_create` MCP tool which is currently stateless).
 *
 * If `bug_report_project_configs.config.test_command` is set we mention it in
 * the closure comment as a verification suggestion. We do NOT execute the
 * command — post-merge sandbox verification needs its own clone+checkout
 * pipeline and is intentionally deferred.
 */
class CloseBugReportOnPrMergeListener
{
    public function __construct(
        private readonly AddSignalCommentAction $addComment,
        private readonly UpdateSignalStatusAction $updateStatus,
    ) {}

    public function handle(SignalIngested $event): void
    {
        $signal = $event->signal;

        if ($signal->source_type !== 'bitbucket') {
            return;
        }

        $tags = (array) ($signal->tags ?? []);
        if (! in_array('pull_request_merged', $tags, true)) {
            return;
        }

        $payload = (array) ($signal->payload ?? []);
        $prInfo = $this->extractPrInfo($payload);

        if ($prInfo['url'] === null) {
            Log::warning('CloseBugReportOnPrMergeListener: merged-PR signal without resolvable PR URL', [
                'signal_id' => $signal->id,
            ]);

            return;
        }

        $bugReport = $this->correlate($prInfo['url'], $signal->team_id);

        if ($bugReport === null) {
            Log::info('CloseBugReportOnPrMergeListener: no originating bug-report found for merged PR', [
                'merged_signal_id' => $signal->id,
                'pr_url' => $prInfo['url'],
            ]);

            return;
        }

        if ($bugReport->status->isTerminal()) {
            return;
        }

        $verificationLine = $this->verificationSuggestion($bugReport);

        $body = sprintf(
            "PR #%s merged: %s\n\n%s%s",
            $prInfo['number'] ?? '?',
            $prInfo['url'],
            $verificationLine !== null ? $verificationLine."\n\n" : '',
            'Closing this bug-report.',
        );

        try {
            $this->addComment->execute(
                signal: $bugReport,
                body: $body,
                authorType: CommentAuthorType::Agent,
                idempotencyKey: 'pr-merged:'.$prInfo['url'],
            );
        } catch (\Throwable $e) {
            Log::warning('CloseBugReportOnPrMergeListener: closure comment failed', [
                'bug_report_signal_id' => $bugReport->id,
                'pr_url' => $prInfo['url'],
                'error' => $e->getMessage(),
            ]);
        }

        $this->stampMergeMetadata($bugReport, $prInfo);

        if ($bugReport->status === SignalStatus::Resolved) {
            return;
        }

        try {
            $this->updateStatus->execute(
                signal: $bugReport,
                newStatus: SignalStatus::Resolved,
            );
        } catch (\Throwable $e) {
            Log::warning('CloseBugReportOnPrMergeListener: status transition to Resolved failed', [
                'bug_report_signal_id' => $bugReport->id,
                'from' => $bugReport->status->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{url: ?string, number: ?int, branch: ?string, merge_sha: ?string}
     */
    private function extractPrInfo(array $payload): array
    {
        $pr = (array) ($payload['pullrequest'] ?? []);

        $url = $pr['links']['html']['href'] ?? null;
        $number = isset($pr['id']) ? (int) $pr['id'] : null;
        $branch = $pr['source']['branch']['name'] ?? null;
        $mergeSha = $pr['merge_commit']['hash'] ?? null;

        return [
            'url' => is_string($url) && $url !== '' ? $url : null,
            'number' => $number,
            'branch' => is_string($branch) && $branch !== '' ? $branch : null,
            'merge_sha' => is_string($mergeSha) && $mergeSha !== '' ? $mergeSha : null,
        ];
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
                    ->where('source_type', 'bug_report')
                    ->first();

                if ($signal !== null) {
                    return $signal;
                }
            }
        }

        // Fallback: agents that post the PR URL in a comment (the
        // `bug_report_add_comment` MCP tool path) leave a discoverable
        // breadcrumb. Restrict to agent-authored comments on bug-reports
        // within this team in the last 30 days.
        $cutoff = Carbon::now()->subDays(30);

        $commentRow = SignalComment::query()
            ->where('team_id', $teamId)
            ->where('author_type', 'agent')
            ->where('created_at', '>=', $cutoff)
            ->where('body', 'like', '%'.$prUrl.'%')
            ->latest('created_at')
            ->first();

        if ($commentRow === null) {
            return null;
        }

        $signal = Signal::withoutGlobalScopes()
            ->where('id', $commentRow->signal_id)
            ->where('source_type', 'bug_report')
            ->first();

        return $signal;
    }

    private function verificationSuggestion(Signal $bugReport): ?string
    {
        $project = $bugReport->project_key;
        if (! is_string($project) || $project === '') {
            return null;
        }

        $config = BugReportProjectConfig::withoutGlobalScopes()
            ->where('team_id', $bugReport->team_id)
            ->where('project', $project)
            ->first();

        $testCommand = $config->config['test_command'] ?? null;
        if (! is_string($testCommand) || $testCommand === '') {
            return null;
        }

        return 'Suggested verification: `'.$testCommand.'`';
    }

    /**
     * @param  array{url: ?string, number: ?int, branch: ?string, merge_sha: ?string}  $prInfo
     */
    private function stampMergeMetadata(Signal $bugReport, array $prInfo): void
    {
        $payload = (array) ($bugReport->payload ?? []);
        $existing = (array) ($payload['merged_prs'] ?? []);

        // Don't double-stamp if the listener fires twice for the same PR URL.
        foreach ($existing as $entry) {
            if (($entry['url'] ?? null) === $prInfo['url']) {
                return;
            }
        }

        $existing[] = array_filter([
            'url' => $prInfo['url'],
            'number' => $prInfo['number'],
            'branch' => $prInfo['branch'],
            'merge_sha' => $prInfo['merge_sha'],
            'merged_at' => Carbon::now()->toIso8601String(),
        ], fn ($v) => $v !== null);

        $payload['merged_prs'] = $existing;
        $bugReport->update(['payload' => $payload]);
    }
}
