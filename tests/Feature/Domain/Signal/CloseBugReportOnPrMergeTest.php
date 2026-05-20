<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Listeners\CloseBugReportOnPrMergeListener;
use App\Domain\Signal\Models\BugReportProjectConfig;
use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\V1\ApiTestCase;

class CloseBugReportOnPrMergeTest extends ApiTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_correlation_via_git_pull_requests_closes_bug_report(): void
    {
        $bugReport = $this->seedBugReportInReview();

        $repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'collector2',
            'url' => 'https://bitbucket.org/lukanet/collector2',
            'provider' => 'bitbucket',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'status' => 'active',
            'commit_discipline' => 'atomic',
        ]);
        $previousExperiment = Experiment::find($bugReport->experiment_id);

        GitPullRequest::create([
            'git_repository_id' => $repo->id,
            'agent_id' => $previousExperiment->agent_id,
            'title' => 'Fix submit',
            'body' => 'desc',
            'branch' => 'fix/submit',
            'base_branch' => 'main',
            'pr_number' => '42',
            'pr_url' => 'https://bitbucket.org/lukanet/collector2/pull-requests/42',
            'status' => 'open',
        ]);

        $mergedSignal = $this->seedMergedPrSignal('https://bitbucket.org/lukanet/collector2/pull-requests/42', 42);

        app(CloseBugReportOnPrMergeListener::class)
            ->handle(new SignalIngested($mergedSignal));

        $this->assertSame(SignalStatus::Resolved, $bugReport->fresh()->status);

        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $bugReport->id,
            'author_type' => 'agent',
            'idempotency_key' => 'pr-merged:https://bitbucket.org/lukanet/collector2/pull-requests/42',
        ]);

        $payload = $bugReport->fresh()->payload;
        $this->assertSame(
            'https://bitbucket.org/lukanet/collector2/pull-requests/42',
            $payload['merged_prs'][0]['url'],
        );
        $this->assertSame('abc123', $payload['merged_prs'][0]['merge_sha']);
    }

    public function test_correlation_via_signal_comment_substring_when_no_git_pull_request_row(): void
    {
        $bugReport = $this->seedBugReportInReview();

        // Simulate the agent's "PR opened" comment (the `bug_report_add_comment`
        // MCP tool path). No GitPullRequest row.
        app(AddSignalCommentAction::class)->execute(
            signal: $bugReport,
            body: '✅ PR opened: https://bitbucket.org/lukanet/collector2/pull-requests/22 — please review.',
            authorType: CommentAuthorType::Agent,
        );

        $mergedSignal = $this->seedMergedPrSignal('https://bitbucket.org/lukanet/collector2/pull-requests/22', 22);

        app(CloseBugReportOnPrMergeListener::class)
            ->handle(new SignalIngested($mergedSignal));

        $this->assertSame(SignalStatus::Resolved, $bugReport->fresh()->status);
        $this->assertDatabaseHas('signal_comments', [
            'signal_id' => $bugReport->id,
            'idempotency_key' => 'pr-merged:https://bitbucket.org/lukanet/collector2/pull-requests/22',
        ]);
    }

    public function test_test_command_is_surfaced_in_closure_comment(): void
    {
        $bugReport = $this->seedBugReportInReview();

        BugReportProjectConfig::create([
            'team_id' => $this->team->id,
            'project' => 'lukanet/collector2',
            'config' => ['test_command' => 'composer test'],
        ]);

        app(AddSignalCommentAction::class)->execute(
            signal: $bugReport,
            body: 'PR open: https://bitbucket.org/lukanet/collector2/pull-requests/9',
            authorType: CommentAuthorType::Agent,
        );

        $mergedSignal = $this->seedMergedPrSignal('https://bitbucket.org/lukanet/collector2/pull-requests/9', 9);

        app(CloseBugReportOnPrMergeListener::class)
            ->handle(new SignalIngested($mergedSignal));

        $closure = SignalComment::query()
            ->where('signal_id', $bugReport->id)
            ->where('idempotency_key', 'pr-merged:https://bitbucket.org/lukanet/collector2/pull-requests/9')
            ->first();

        $this->assertNotNull($closure);
        $this->assertStringContainsString('Suggested verification:', (string) $closure->body);
        $this->assertStringContainsString('composer test', (string) $closure->body);
    }

    public function test_no_correlation_does_not_close_anything(): void
    {
        $bugReport = $this->seedBugReportInReview();

        $mergedSignal = $this->seedMergedPrSignal('https://bitbucket.org/foo/bar/pull-requests/999', 999);

        app(CloseBugReportOnPrMergeListener::class)
            ->handle(new SignalIngested($mergedSignal));

        $this->assertSame(SignalStatus::Review, $bugReport->fresh()->status);
        $this->assertDatabaseMissing('signal_comments', [
            'signal_id' => $bugReport->id,
            'idempotency_key' => 'pr-merged:https://bitbucket.org/foo/bar/pull-requests/999',
        ]);
    }

    public function test_terminal_bug_report_is_not_re_closed(): void
    {
        $bugReport = $this->seedBugReportInReview();
        $bugReport->update(['status' => SignalStatus::Resolved]);

        app(AddSignalCommentAction::class)->execute(
            signal: $bugReport,
            body: 'PR open: https://bitbucket.org/lukanet/collector2/pull-requests/100',
            authorType: CommentAuthorType::Agent,
        );

        $mergedSignal = $this->seedMergedPrSignal('https://bitbucket.org/lukanet/collector2/pull-requests/100', 100);

        $beforeCommentCount = SignalComment::where('signal_id', $bugReport->id)->count();

        app(CloseBugReportOnPrMergeListener::class)
            ->handle(new SignalIngested($mergedSignal));

        $this->assertSame(
            $beforeCommentCount,
            SignalComment::where('signal_id', $bugReport->id)->count(),
            'No closure comment should be added when the bug report is already terminal.',
        );
    }

    public function test_idempotent_under_repeat_handle(): void
    {
        $bugReport = $this->seedBugReportInReview();

        app(AddSignalCommentAction::class)->execute(
            signal: $bugReport,
            body: 'PR: https://bitbucket.org/lukanet/collector2/pull-requests/55',
            authorType: CommentAuthorType::Agent,
        );

        $mergedSignal = $this->seedMergedPrSignal('https://bitbucket.org/lukanet/collector2/pull-requests/55', 55);

        $listener = app(CloseBugReportOnPrMergeListener::class);
        $listener->handle(new SignalIngested($mergedSignal));
        $listener->handle(new SignalIngested($mergedSignal));

        $closureCount = SignalComment::query()
            ->where('signal_id', $bugReport->id)
            ->where('idempotency_key', 'pr-merged:https://bitbucket.org/lukanet/collector2/pull-requests/55')
            ->count();

        $this->assertSame(1, $closureCount, 'Closure comment must dedupe on repeat.');

        $payload = $bugReport->fresh()->payload;
        $this->assertCount(1, $payload['merged_prs'] ?? [], 'merged_prs metadata must not duplicate.');
    }

    public function test_non_bitbucket_signal_is_ignored(): void
    {
        $bugReport = $this->seedBugReportInReview();
        $beforeStatus = $bugReport->status;

        $unrelated = Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'github',
            'source_identifier' => 'gh:foo/bar:1',
            'project_key' => 'foo/bar',
            'status' => SignalStatus::Received,
            'content_hash' => md5('gh-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => ['pullrequest' => ['links' => ['html' => ['href' => 'https://github.com/foo/bar/pull/1']]]],
            'tags' => ['github', 'pull_request_merged'],
        ]);

        app(CloseBugReportOnPrMergeListener::class)
            ->handle(new SignalIngested($unrelated));

        $this->assertSame($beforeStatus, $bugReport->fresh()->status);
    }

    private function seedBugReportInReview(): Signal
    {
        $agent = Agent::factory()->for($this->team)->create([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5',
        ]);

        $previousExperiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'agent_id' => $agent->id,
            'title' => 'Fix bug: original',
            'thesis' => 'original thesis',
            'track' => 'debug',
            'status' => ExperimentStatus::Completed,
        ]);

        return Signal::create([
            'team_id' => $this->team->id,
            'experiment_id' => $previousExperiment->id,
            'source_type' => 'bug_report',
            'source_identifier' => 'lukanet/collector2',
            'project_key' => 'lukanet/collector2',
            'status' => SignalStatus::Review,
            'content_hash' => md5('br-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'title' => 'Submit broken',
                'description' => 'edit form blank',
                'severity' => 'major',
                'url' => 'https://app.example.com/edit',
                'reporter_id' => 'r-1',
                'reporter_name' => 'Alice',
                'action_log' => [],
                'console_log' => [],
                'network_log' => [],
                'browser' => 'Mozilla/5.0',
                'viewport' => '1440x900',
                'environment' => 'production',
            ],
            'tags' => ['bug_report'],
        ]);
    }

    private function seedMergedPrSignal(string $prUrl, int $prNumber): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'bitbucket',
            'source_identifier' => "bitbucket:lukanet/collector2:{$prNumber}",
            'project_key' => null,
            'status' => SignalStatus::Received,
            'content_hash' => md5('bb-merged-'.$prNumber.'-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'pullrequest' => [
                    'id' => $prNumber,
                    'links' => ['html' => ['href' => $prUrl]],
                    'source' => ['branch' => ['name' => 'fix/submit']],
                    'merge_commit' => ['hash' => 'abc123'],
                ],
            ],
            'tags' => ['bitbucket', 'pull_request_merged'],
        ]);
    }
}
