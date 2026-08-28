<?php

namespace Tests\Feature\Mcp;

use App\Domain\Approval\Actions\CreateApprovalRequestAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\GitRepository\GitPullRequestCreateTool;
use App\Mcp\Tools\GitRepository\GitPullRequestMergeTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Mockery;
use Tests\TestCase;

/**
 * pr.require_approval was write-only: GitPullRequestCreateTool created an
 * ApprovalRequest and nothing ever read it back, so git_pr_merge merged
 * regardless. These tests pin the gate closed.
 */
class GitPullRequestApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        app()->instance('mcp.team_id', $this->team->id);

        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'test-repo',
            'url' => 'https://github.com/example/test',
            'provider' => 'github',
            'mode' => 'api_only',
            'default_branch' => 'main',
            'config' => ['pr' => ['require_approval' => true]],
            'status' => 'active',
        ]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    /**
     * Binds a client that fails the test if any method is called. A refused
     * merge must perform no side effect — asserting only on the error message
     * would pass even if the PR had already been merged.
     */
    private function expectNoGitCalls(): void
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldNotReceive('mergePullRequest');
        $client->shouldNotReceive('getPullRequestStatus');

        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);
    }

    private function expectMerge(): void
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('getPullRequestStatus')->andReturn([
            'mergeable' => true,
            'ci_passing' => true,
        ]);
        $client->shouldReceive('mergePullRequest')->once()->andReturn([
            'sha' => 'deadbeef',
            'merged' => true,
            'message' => 'Merged',
        ]);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);
    }

    private function makePr(?ApprovalRequest $approval = null, int $number = 7): GitPullRequest
    {
        return GitPullRequest::create([
            'git_repository_id' => $this->repo->id,
            'agent_id' => null,
            'approval_request_id' => $approval?->id,
            'title' => 'Some change',
            'body' => '',
            'branch' => 'feat/x',
            'base_branch' => 'main',
            'pr_number' => (string) $number,
            'pr_url' => 'https://github.com/example/test/pull/'.$number,
            'status' => 'open',
        ]);
    }

    private function makeApproval(ApprovalStatus $status, ?string $expiresAt = null, ?string $teamId = null): ApprovalRequest
    {
        return ApprovalRequest::create([
            'team_id' => $teamId ?? $this->team->id,
            'status' => $status,
            'context' => ['pr_number' => 7],
            'expires_at' => $expiresAt,
        ]);
    }

    private function merge(array $extra = []): Response
    {
        return app(GitPullRequestMergeTool::class)->handle(new Request(array_merge([
            'repository_id' => $this->repo->id,
            'pr_number' => 7,
        ], $extra)));
    }

    private function assertRefused(Response $response): void
    {
        $this->assertTrue($response->isError(), 'Expected the merge to be refused.');
        $this->assertSame('FAILED_PRECONDITION', $this->decode($response)['error']['code']);
    }

    // ---- merge gate --------------------------------------------------------

    public function test_merges_normally_when_require_approval_is_off(): void
    {
        $this->repo->update(['config' => []]);
        $this->makePr();
        $this->expectMerge();

        $response = $this->merge();

        $this->assertFalse($response->isError());
        $this->assertTrue($this->decode($response)['merged']);
    }

    public function test_refuses_when_no_platform_record_exists(): void
    {
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    public function test_refuses_when_pr_has_no_approval_linked(): void
    {
        $this->makePr();
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    public function test_refuses_while_approval_is_pending(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Pending));
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    public function test_refuses_when_approval_was_rejected(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Rejected));
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    public function test_refuses_when_approval_status_is_expired(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Expired));
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    /**
     * ExpireStaleApprovals only sweeps pending rows, so an approved row can sit
     * past its deadline still marked Approved. Expiry must be re-derived here.
     */
    public function test_refuses_when_approved_but_past_expires_at(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Approved, now()->subHour()->toDateTimeString()));
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    public function test_merges_when_approved_without_expiry(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Approved));
        $this->expectMerge();

        $response = $this->merge();

        $this->assertFalse($response->isError());
        $this->assertTrue($this->decode($response)['merged']);
    }

    public function test_merges_when_approved_and_not_yet_expired(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Approved, now()->addHour()->toDateTimeString()));
        $this->expectMerge();

        $this->assertFalse($this->merge()->isError());
    }

    public function test_force_does_not_bypass_the_approval_gate(): void
    {
        $this->makePr($this->makeApproval(ApprovalStatus::Pending));
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge(['force' => true]));
    }

    public function test_refuses_approval_belonging_to_another_team(): void
    {
        $otherOwner = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other '.bin2hex(random_bytes(3)),
            'slug' => 'other-'.bin2hex(random_bytes(3)),
            'owner_id' => $otherOwner->id,
            'settings' => [],
        ]);

        $foreign = $this->makeApproval(ApprovalStatus::Approved, null, $otherTeam->id);
        $this->makePr($foreign);
        $this->expectNoGitCalls();

        $this->assertRefused($this->merge());
    }

    // ---- create fail-open --------------------------------------------------

    public function test_create_reports_and_stays_gated_when_approval_creation_fails(): void
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->shouldReceive('createPullRequest')->andReturn([
            'title' => 'Some change',
            'pr_number' => 7,
            'pr_url' => 'https://github.com/example/test/pull/7',
        ]);
        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $failing = Mockery::mock(CreateApprovalRequestAction::class);
        $failing->shouldReceive('execute')->andThrow(new \RuntimeException('approval store down'));
        app()->instance(CreateApprovalRequestAction::class, $failing);

        $response = app(GitPullRequestCreateTool::class)->handle(new Request([
            'repository_id' => $this->repo->id,
            'title' => 'Some change',
            'head' => 'feat/x',
        ]));

        $data = $this->decode($response);

        // The PR exists upstream and cannot be unwound, but the caller must not
        // be told approval is unnecessary.
        $this->assertTrue($data['success']);
        $this->assertTrue($data['requires_approval']);
        $this->assertNull($data['approval_request_id']);
        $this->assertArrayHasKey('approval_error', $data);

        // ...and the PR must now be unmergeable, not ungated.
        $this->expectNoGitCalls();
        $this->assertRefused($this->merge());
    }
}
