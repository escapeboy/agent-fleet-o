<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Integration\Actions\ExecuteIntegrationActionAction;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Events\SignalIngested;
use App\Domain\Signal\Listeners\CloseSentryIssueOnPrMergeListener;
use App\Domain\Signal\Models\Signal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TC-23..TC-26 from test-plan-sentry-watchdog.md §4.
 *
 * ExecuteIntegrationActionAction (the Sentry API call) is mocked — this test
 * covers the listener's correlation, idempotency, and status orchestration,
 * not the integration driver itself.
 */
class CloseSentryIssueOnPrMergeListenerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{action: string, params: array<string, mixed>}> */
    private array $integrationCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->integrationCalls = [];

        $this->mock(ExecuteIntegrationActionAction::class, function (MockInterface $mock) {
            $mock->shouldReceive('execute')->andReturnUsing(function ($integration, $action, $params) {
                $this->integrationCalls[] = ['action' => $action, 'params' => $params];

                return ['ok' => true];
            });
        });
    }

    /** @return list<string> */
    private function calledActions(): array
    {
        return array_map(fn (array $call) => $call['action'], $this->integrationCalls);
    }

    public function test_tc23_pr_merge_for_delegated_sentry_experiment_resolves_issue(): void
    {
        $sentrySignal = $this->seedDelegatedSentrySignal('SENTRY-1');
        $this->seedSentryIntegration($sentrySignal->team_id);
        $this->seedGitPullRequest(
            teamId: $sentrySignal->team_id,
            agentId: Experiment::find($sentrySignal->experiment_id)->agent_id,
            prUrl: 'https://github.com/lukanet/fleetq/pull/42',
        );

        $merged = $this->seedMergedPrSignal($sentrySignal->team_id, 'https://github.com/lukanet/fleetq/pull/42', 42);

        app(CloseSentryIssueOnPrMergeListener::class)->handle(new SignalIngested($merged));

        $this->assertContains('resolve_issue', $this->calledActions());
        $this->assertContains('create_note', $this->calledActions());
        $this->assertSame('SENTRY-1', $this->integrationCalls[0]['params']['issue_id']);
        $this->assertSame(SignalStatus::Resolved, $sentrySignal->fresh()->status);
        $this->assertSame(
            'https://github.com/lukanet/fleetq/pull/42',
            $sentrySignal->fresh()->payload['sentry_resolved']['pr_url'],
        );
    }

    public function test_tc24_unrelated_pr_merge_makes_no_sentry_calls(): void
    {
        $sentrySignal = $this->seedDelegatedSentrySignal('SENTRY-1');
        $this->seedSentryIntegration($sentrySignal->team_id);

        $merged = $this->seedMergedPrSignal($sentrySignal->team_id, 'https://github.com/foo/bar/pull/999', 999);

        app(CloseSentryIssueOnPrMergeListener::class)->handle(new SignalIngested($merged));

        $this->assertSame([], $this->integrationCalls);
        $this->assertSame(SignalStatus::DelegatedToAgent, $sentrySignal->fresh()->status);
    }

    public function test_tc25_already_resolved_sentry_signal_is_not_re_resolved(): void
    {
        $sentrySignal = $this->seedDelegatedSentrySignal('SENTRY-1');
        $sentrySignal->update(['status' => SignalStatus::Resolved]);
        $this->seedSentryIntegration($sentrySignal->team_id);
        $this->seedGitPullRequest(
            teamId: $sentrySignal->team_id,
            agentId: Experiment::find($sentrySignal->experiment_id)->agent_id,
            prUrl: 'https://github.com/lukanet/fleetq/pull/77',
        );

        $merged = $this->seedMergedPrSignal($sentrySignal->team_id, 'https://github.com/lukanet/fleetq/pull/77', 77);

        app(CloseSentryIssueOnPrMergeListener::class)->handle(new SignalIngested($merged));

        $this->assertSame([], $this->integrationCalls);
    }

    public function test_tc26_duplicate_pr_merge_event_is_idempotent(): void
    {
        $sentrySignal = $this->seedDelegatedSentrySignal('SENTRY-1');
        $this->seedSentryIntegration($sentrySignal->team_id);
        $this->seedGitPullRequest(
            teamId: $sentrySignal->team_id,
            agentId: Experiment::find($sentrySignal->experiment_id)->agent_id,
            prUrl: 'https://github.com/lukanet/fleetq/pull/55',
        );

        $merged = $this->seedMergedPrSignal($sentrySignal->team_id, 'https://github.com/lukanet/fleetq/pull/55', 55);
        $listener = app(CloseSentryIssueOnPrMergeListener::class);

        $listener->handle(new SignalIngested($merged));
        $listener->handle(new SignalIngested($merged));

        $resolveCalls = array_filter($this->calledActions(), fn (string $a) => $a === 'resolve_issue');
        $this->assertCount(1, $resolveCalls, 'resolve_issue must fire exactly once across both events.');
        $this->assertSame(SignalStatus::Resolved, $sentrySignal->fresh()->status);
    }

    private function seedDelegatedSentrySignal(string $sentryIssueId): Signal
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $agent = Agent::factory()->for($team)->create();

        $experiment = Experiment::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'agent_id' => $agent->id,
        ]);

        return Signal::create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => 'fleetq',
            'status' => SignalStatus::DelegatedToAgent,
            'content_hash' => md5('sentry-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'title' => 'NullPointer in checkout',
                'sentry_issue_id' => $sentryIssueId,
                'suspect_files' => [['path' => 'app/Http/Controllers/CheckoutController.php']],
            ],
            'tags' => ['sentry', 'issue', 'error'],
        ]);
    }

    private function seedSentryIntegration(string $teamId): Integration
    {
        return Integration::factory()->create([
            'team_id' => $teamId,
            'driver' => 'sentry',
            'name' => 'Sentry fleetq',
            'status' => IntegrationStatus::Active,
            'config' => ['org_slug' => 'acme'],
        ]);
    }

    private function seedGitPullRequest(string $teamId, string $agentId, string $prUrl): GitPullRequest
    {
        $repo = GitRepository::create([
            'team_id' => $teamId,
            'name' => 'fleetq',
            'url' => 'https://github.com/lukanet/fleetq',
            'provider' => 'github',
            'default_branch' => 'develop',
        ]);

        return GitPullRequest::create([
            'git_repository_id' => $repo->id,
            'agent_id' => $agentId,
            'title' => 'Fix checkout NPE',
            'branch' => 'fix/checkout-npe',
            'base_branch' => 'develop',
            'pr_number' => (string) basename($prUrl),
            'pr_url' => $prUrl,
            'status' => 'open',
        ]);
    }

    private function seedMergedPrSignal(string $teamId, string $prUrl, int $prNumber): Signal
    {
        return Signal::create([
            'team_id' => $teamId,
            'source_type' => 'github',
            'source_identifier' => "lukanet/fleetq#PR-{$prNumber}",
            'status' => SignalStatus::Received,
            'content_hash' => md5('gh-merged-'.$prNumber.'-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'event' => 'pull_request',
                'action' => 'merged',
                'url' => $prUrl,
                'pr_number' => $prNumber,
                'repo' => 'lukanet/fleetq',
                'merged' => true,
            ],
            'tags' => ['github', 'pull_request', 'merged'],
        ]);
    }
}
