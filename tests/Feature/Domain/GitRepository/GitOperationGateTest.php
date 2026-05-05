<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\GitRepository\Exceptions\GitOperationProposedException;
use App\Domain\GitRepository\Exceptions\GitOperationRefusedException;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationGate;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GitOperationGateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->actingAs($this->user);

        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'test-repo',
            'url' => 'https://github.com/example/test',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => [],
            'status' => 'active',
        ]);
    }

    public function test_default_policy_passes_through(): void
    {
        $gate = app(GitOperationGate::class);
        $gate->check($this->repo, 'commit', ['changes' => [], 'message' => 'x', 'branch' => 'main']);

        $this->assertSame(0, ActionProposal::count());
    }

    public function test_policy_ask_at_high_creates_proposal_and_throws(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'auto',
            'high' => 'ask',
        ]]]);

        $gate = app(GitOperationGate::class);

        try {
            $gate->check($this->repo, 'mergePullRequest', ['pr_number' => 42, 'method' => 'squash']);
            $this->fail('Expected GitOperationProposedException');
        } catch (GitOperationProposedException $e) {
            $this->assertSame('high', $e->riskLevel);
            $this->assertSame('mergePullRequest', $e->method);

            $proposal = ActionProposal::find($e->proposalId);
            $this->assertNotNull($proposal);
            $this->assertSame('git_push', $proposal->target_type);
            $this->assertSame($this->repo->id, $proposal->target_id);
            $this->assertSame('mergePullRequest', $proposal->payload['method']);
            $this->assertSame(42, $proposal->payload['args']['pr_number']);
            $this->assertSame(ActionProposalStatus::Pending, $proposal->status);
        }
    }

    public function test_policy_ask_at_medium_proposes_commits(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'ask',
            'high' => 'ask',
        ]]]);

        $gate = app(GitOperationGate::class);

        try {
            $gate->check($this->repo, 'commit', ['changes' => [['path' => 'a.txt', 'content' => 'hi']], 'message' => 'fix', 'branch' => 'main']);
            $this->fail('Expected GitOperationProposedException');
        } catch (GitOperationProposedException $e) {
            $this->assertSame('medium', $e->riskLevel);
            $this->assertSame(1, ActionProposal::count());
        }
    }

    public function test_policy_reject_throws_without_proposal(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'auto',
            'high' => 'reject',
        ]]]);

        $gate = app(GitOperationGate::class);

        try {
            $gate->check($this->repo, 'createRelease', ['tag_name' => 'v1.0.0', 'name' => 'v1', 'body' => '']);
            $this->fail('Expected GitOperationRefusedException');
        } catch (GitOperationRefusedException $e) {
            $this->assertSame('high', $e->riskLevel);
            $this->assertStringContainsString('refused by team policy', $e->getMessage());
        }

        $this->assertSame(0, ActionProposal::count(), 'reject must NOT create a proposal');
    }

    public function test_legacy_slow_mode_enabled_maps_to_high_ask(): void
    {
        $this->team->update(['settings' => ['slow_mode_enabled' => true]]);

        $gate = app(GitOperationGate::class);

        // medium passes (legacy slow_mode only gates high)
        $gate->check($this->repo, 'commit', ['changes' => [], 'message' => 'x', 'branch' => 'main']);
        $this->assertSame(0, ActionProposal::count());

        // high gated
        $this->expectException(GitOperationProposedException::class);
        $gate->check($this->repo, 'mergePullRequest', ['pr_number' => 1]);
    }

    public function test_bypass_binding_short_circuits_gate(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => ['low' => 'auto', 'medium' => 'auto', 'high' => 'reject']]]);

        app()->instance('git_gate.bypass', true);
        try {
            // High-risk method that would normally be REJECTED — bypass lets it through.
            app(GitOperationGate::class)->check($this->repo, 'mergePullRequest', ['pr_number' => 1]);
        } finally {
            app()->forgetInstance('git_gate.bypass');
        }

        $this->assertSame(0, ActionProposal::count());
    }

    public function test_unknown_method_defaults_to_high_risk(): void
    {
        $this->team->update(['settings' => ['action_proposal_policy' => [
            'low' => 'auto',
            'medium' => 'auto',
            'high' => 'reject',
        ]]]);

        $this->expectException(GitOperationRefusedException::class);
        app(GitOperationGate::class)->check($this->repo, 'someUnknownFutureMethod', []);
    }

    /**
     * @dataProvider riskClassificationCases
     */
    public function test_classify_method(string $method, string $expected): void
    {
        $this->assertSame($expected, GitOperationGate::classifyMethod($method));
    }

    public static function riskClassificationCases(): array
    {
        return [
            // low — read
            ['ping', 'low'],
            ['readFile', 'low'],
            ['listFiles', 'low'],
            ['getFileTree', 'low'],
            ['listPullRequests', 'low'],
            ['getPullRequestStatus', 'low'],
            ['getCommitLog', 'low'],
            // medium — branch / commit / push / single-file write
            ['createBranch', 'medium'],
            ['commit', 'medium'],
            ['push', 'medium'],
            ['writeFile', 'medium'],
            // high — PR lifecycle / dispatch / release
            ['createPullRequest', 'high'],
            ['mergePullRequest', 'high'],
            ['closePullRequest', 'high'],
            ['dispatchWorkflow', 'high'],
            ['createRelease', 'high'],
            // unknown defaults to high (safe default)
            ['someFutureMethod', 'high'],
        ];
    }
}
