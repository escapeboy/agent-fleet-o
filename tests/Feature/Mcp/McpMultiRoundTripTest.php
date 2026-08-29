<?php

namespace Tests\Feature\Mcp;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Mcp\Methods\MultiRoundTripCallTool;
use App\Mcp\Protocol\ProtocolContext;
use App\Mcp\Protocol\ProtocolVersions;
use App\Mcp\Protocol\RequestState;
use App\Mcp\Tools\GitRepository\GitPullRequestMergeTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Mockery;
use Tests\TestCase;

/**
 * SEP-2322 multi round-trip requests, exercised end to end through the
 * `tools/call` method rather than by calling the tool directly — the whole
 * point of the feature lives in the method/tool seam.
 */
class McpMultiRoundTripTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

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

    protected function tearDown(): void
    {
        app()->forgetInstance(ProtocolContext::BINDING);

        parent::tearDown();
    }

    // ---- helpers -----------------------------------------------------------

    private function negotiate(string $version): void
    {
        app()->instance(ProtocolContext::BINDING, new ProtocolContext(
            version: $version,
            stateless: true,
        ));
    }

    private function context(): ServerContext
    {
        return new ServerContext(
            supportedProtocolVersions: ProtocolVersions::SUPPORTED,
            serverCapabilities: [],
            serverName: 'test',
            serverVersion: '1.0',
            instructions: '',
            maxPaginationLength: 100,
            defaultPaginationLength: 50,
            tools: [GitPullRequestMergeTool::class],
            resources: [],
            prompts: [],
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $extraParams
     * @return array<string, mixed>
     */
    private function callTool(array $arguments, array $extraParams = []): array
    {
        $request = new JsonRpcRequest(
            id: 1,
            method: 'tools/call',
            params: array_merge([
                'name' => 'git_pr_merge',
                'arguments' => $arguments,
            ], $extraParams),
        );

        // Server::handleRequest binds this before dispatching a method; the
        // McpServiceProvider resolving-callback populates the tool's Request
        // from it. Invoking the method directly means mirroring that contract.
        app()->instance('mcp.request', $request->toRequest());

        try {
            $response = app(MultiRoundTripCallTool::class)->handle($request, $this->context());
        } finally {
            app()->forgetInstance('mcp.request');
        }

        return $response->toArray()['result'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeArgs(array $extra = []): array
    {
        return array_merge([
            'repository_id' => $this->repo->id,
            'pr_number' => 7,
        ], $extra);
    }

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
        $client->shouldReceive('getPullRequestStatus')->andReturn(['mergeable' => true, 'ci_passing' => true]);
        $client->shouldReceive('mergePullRequest')->once()->andReturn([
            'sha' => 'deadbeef', 'merged' => true, 'message' => 'Merged',
        ]);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->shouldReceive('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);
    }

    private function makeApproval(ApprovalStatus $status, ?string $teamId = null): ApprovalRequest
    {
        return ApprovalRequest::create([
            'team_id' => $teamId ?? $this->team->id,
            'status' => $status,
            'context' => ['pr_number' => 7],
        ]);
    }

    private function makePr(?ApprovalRequest $approval): GitPullRequest
    {
        return GitPullRequest::create([
            'git_repository_id' => $this->repo->id,
            'agent_id' => null,
            'approval_request_id' => $approval?->id,
            'title' => 'Some change',
            'body' => '',
            'branch' => 'feat/x',
            'base_branch' => 'main',
            'pr_number' => '7',
            'pr_url' => 'https://github.com/example/test/pull/7',
            'status' => 'open',
        ]);
    }

    // ---- the protocol shape ------------------------------------------------

    public function test_pending_approval_returns_input_required_with_request_state(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Pending));
        $this->expectNoGitCalls();

        $result = $this->callTool($this->mergeArgs());

        $this->assertSame('input_required', $result['resultType']);
        $this->assertNotEmpty($result['requestState']);
        $this->assertArrayHasKey('pr_merge_approval', $result['inputRequests']);
        $this->assertSame('elicitation/create', $result['inputRequests']['pr_merge_approval']['method']);

        // Not a terminal result: the spec's isError/content shape must be absent.
        $this->assertArrayNotHasKey('isError', $result);
    }

    public function test_successful_result_carries_no_result_type(): void
    {
        // "If this field is not provided, the Client should assume a ResultType
        // of 'complete'" — so completed calls must stay byte-identical.
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Approved));
        $this->expectMerge();

        $result = $this->callTool($this->mergeArgs());

        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertFalse($result['isError']);
    }

    public function test_pre_mrtr_client_gets_the_terminal_fallback(): void
    {
        $this->negotiate(ProtocolVersions::V2025_06_18);
        $this->makePr($this->makeApproval(ApprovalStatus::Pending));
        $this->expectNoGitCalls();

        $result = $this->callTool($this->mergeArgs());

        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertArrayNotHasKey('requestState', $result);
        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('awaiting approval', $result['content'][0]['text']);
    }

    // ---- the round trip ----------------------------------------------------

    public function test_retry_with_state_merges_once_the_approval_is_granted(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $approval = $this->makeApproval(ApprovalStatus::Pending);
        $this->makePr($approval);
        $this->expectNoGitCalls();

        $first = $this->callTool($this->mergeArgs());
        $state = $first['requestState'];

        // The human actions it out of band, then the client retries.
        $approval->update(['status' => ApprovalStatus::Approved]);
        $this->expectMerge();

        $second = $this->callTool($this->mergeArgs(), [
            'requestState' => $state,
            'inputResponses' => ['pr_merge_approval' => ['action' => 'accept', 'content' => ['acknowledged' => true]]],
        ]);

        $this->assertArrayNotHasKey('resultType', $second);
        $this->assertFalse($second['isError']);
    }

    public function test_retry_while_still_pending_returns_input_required_again(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Pending));
        $this->expectNoGitCalls();

        $state = $this->callTool($this->mergeArgs())['requestState'];

        $again = $this->callTool($this->mergeArgs(), ['requestState' => $state]);

        $this->assertSame('input_required', $again['resultType']);
    }

    /**
     * The elicitation is a nudge, not the authorisation — a client claiming it
     * approved must not get past a still-pending row.
     */
    public function test_client_claiming_approval_cannot_bypass_the_record(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Pending));
        $this->expectNoGitCalls();

        $state = $this->callTool($this->mergeArgs())['requestState'];

        $result = $this->callTool($this->mergeArgs(), [
            'requestState' => $state,
            'inputResponses' => ['pr_merge_approval' => ['action' => 'accept', 'content' => ['acknowledged' => true]]],
        ]);

        $this->assertSame('input_required', $result['resultType']);
    }

    public function test_rejected_approval_is_terminal_not_resumable(): void
    {
        // Looping a decided outcome forever would be worse than refusing it.
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Rejected));
        $this->expectNoGitCalls();

        $result = $this->callTool($this->mergeArgs());

        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertTrue($result['isError']);
    }

    // ---- requestState validation (the MUSTs) --------------------------------

    public function test_garbage_request_state_is_refused(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Approved));
        $this->expectNoGitCalls();

        $result = $this->callTool($this->mergeArgs(), ['requestState' => 'not-a-real-envelope']);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('not valid', $result['content'][0]['text']);
    }

    public function test_state_minted_for_another_call_is_refused(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Approved));
        $this->expectNoGitCalls();

        $foreign = RequestState::issue(
            approvalRequestId: (string) \Illuminate\Support\Str::uuid(),
            teamId: $this->team->id,
            userId: (string) $this->user->id,
            tool: 'git_pr_merge',
            arguments: ['repository_id' => $this->repo->id, 'pr_number' => 999],
        )->encode();

        $result = $this->callTool($this->mergeArgs(), ['requestState' => $foreign]);

        $this->assertTrue($result['isError']);
        $this->assertStringContainsString('different call', $result['content'][0]['text']);
    }

    public function test_state_minted_for_another_team_is_refused(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Approved));
        $this->expectNoGitCalls();

        $foreign = RequestState::issue(
            approvalRequestId: (string) \Illuminate\Support\Str::uuid(),
            teamId: (string) \Illuminate\Support\Str::uuid(),
            userId: (string) $this->user->id,
            tool: 'git_pr_merge',
            arguments: $this->mergeArgs(),
        )->encode();

        $result = $this->callTool($this->mergeArgs(), ['requestState' => $foreign]);

        $this->assertTrue($result['isError']);
    }

    public function test_state_minted_for_another_user_is_refused(): void
    {
        $this->negotiate(ProtocolVersions::V2026_07_28);
        $this->makePr($this->makeApproval(ApprovalStatus::Approved));
        $this->expectNoGitCalls();

        $foreign = RequestState::issue(
            approvalRequestId: (string) \Illuminate\Support\Str::uuid(),
            teamId: $this->team->id,
            userId: (string) User::factory()->create()->id,
            tool: 'git_pr_merge',
            arguments: $this->mergeArgs(),
        )->encode();

        $result = $this->callTool($this->mergeArgs(), ['requestState' => $foreign]);

        $this->assertTrue($result['isError']);
    }
}
