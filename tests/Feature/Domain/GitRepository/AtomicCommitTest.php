<?php

namespace Tests\Feature\Domain\GitRepository;

use App\Domain\GitRepository\Actions\GenerateCommitMessageAction;
use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Enums\CommitDiscipline;
use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Enums\GitRepositoryStatus;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\Git\Clients\AtomicCommittingGitClient;
use App\Infrastructure\Git\Clients\GitHubApiClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aider-inspired atomic commit middleware (build #2, Trendshift top-5 sprint).
 */
class AtomicCommitTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private GitRepository $repo;

    private FakeGitClient $inner;

    private RecordingGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        User::factory()->create(['current_team_id' => $this->team->id]);

        $this->repo = GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'test-repo',
            'url' => 'https://github.com/example/repo',
            'provider' => GitProvider::GitHub->value,
            'mode' => GitRepoMode::ApiOnly->value,
            'status' => GitRepositoryStatus::Active->value,
            'commit_discipline' => CommitDiscipline::Atomic->value,
        ]);

        $this->inner = new FakeGitClient;
        $this->gateway = new RecordingGateway;
        $this->app->instance(AiGatewayInterface::class, $this->gateway);
    }

    private function client(): AtomicCommittingGitClient
    {
        return new AtomicCommittingGitClient(
            inner: $this->inner,
            repo: $this->repo->fresh(),
            messageGen: app(GenerateCommitMessageAction::class),
        );
    }

    public function test_write_file_rewrites_message_via_weak_model(): void
    {
        $this->gateway->reply = 'feat: add greeting endpoint';

        $sha = $this->client()->writeFile('app/routes.php', "<?php\nRoute::get('/hi');", 'WIP', 'main');

        $this->assertSame('sha-abc', $sha);
        $this->assertCount(1, $this->inner->writes);
        $this->assertSame('feat: add greeting endpoint', $this->inner->writes[0]['message']);
        $this->assertCount(1, $this->gateway->requests);
        $this->assertSame('claude-haiku-4-5', $this->gateway->requests[0]->model);
    }

    public function test_message_is_sanitized_into_conventional_commits_when_model_skips_prefix(): void
    {
        $this->gateway->reply = 'Add health check endpoint.';

        $this->client()->writeFile('app/routes.php', '<?php', 'whatever', 'main');

        $written = $this->inner->writes[0]['message'];
        $this->assertSame('chore: add health check endpoint', $written);
    }

    public function test_message_is_capped_at_72_chars(): void
    {
        $this->gateway->reply = 'feat: '.str_repeat('a', 200);

        $this->client()->writeFile('app/routes.php', '<?php', 'whatever', 'main');

        $this->assertSame(72, mb_strlen($this->inner->writes[0]['message']));
    }

    public function test_falls_back_to_caller_message_when_gateway_throws(): void
    {
        $this->gateway->throw = new \RuntimeException('rate limited');

        $this->client()->writeFile('app/routes.php', '<?php', 'fix: sanitize input', 'main');

        // Caller message was already Conventional-shaped → preserved verbatim (sanitized).
        $this->assertSame('fix: sanitize input', $this->inner->writes[0]['message']);
    }

    public function test_falls_back_to_chore_template_when_caller_message_is_blank_and_gateway_throws(): void
    {
        $this->gateway->throw = new \RuntimeException('rate limited');

        $this->client()->writeFile('app/routes.php', '<?php', '', 'main');

        $this->assertStringStartsWith('chore: ', $this->inner->writes[0]['message']);
        $this->assertStringContainsString('app/routes.php', $this->inner->writes[0]['message']);
    }

    public function test_batch_commit_rewrites_message_using_paths_from_changes(): void
    {
        $this->gateway->reply = 'refactor: split routes by domain';

        $this->client()->commit(
            changes: [
                ['path' => 'app/routes/web.php', 'content' => "<?php\nRoute::get('/web');"],
                ['path' => 'app/routes/api.php', 'content' => "<?php\nRoute::get('/api');"],
            ],
            message: 'reorg',
            branch: 'main',
        );

        $this->assertSame('refactor: split routes by domain', $this->inner->commits[0]['message']);
    }

    public function test_batch_commit_marked_delete_when_only_deletions(): void
    {
        $this->gateway->reply = 'chore: drop legacy adapters';

        $this->client()->commit(
            changes: [
                ['path' => 'app/Legacy/A.php', 'deleted' => true],
                ['path' => 'app/Legacy/B.php', 'deleted' => true],
            ],
            message: 'cleanup',
            branch: 'main',
        );

        $this->assertSame('chore: drop legacy adapters', $this->inner->commits[0]['message']);
        // Verify the LLM was invoked with delete kind: prompt should mention "Operation: delete"
        $this->assertStringContainsString('Operation: delete', $this->gateway->requests[0]->userPrompt);
    }

    public function test_read_only_methods_are_passed_through_unchanged(): void
    {
        $this->inner->fileContent = 'hello world';

        $client = $this->client();

        $this->assertTrue($client->ping());
        $this->assertSame('hello world', $client->readFile('foo.txt'));
        $this->assertCount(0, $this->inner->writes);
        $this->assertCount(0, $this->gateway->requests);
    }

    public function test_router_does_not_wrap_when_discipline_is_off(): void
    {
        $this->repo->update(['commit_discipline' => CommitDiscipline::Off->value]);

        // Stub the underlying GitHubApiClient so resolution doesn't hit the network/auth.
        $this->app->bind(GitHubApiClient::class, fn () => $this->inner);

        $router = new GitOperationRouter;
        $outer = $router->resolve($this->repo->fresh());

        // Outermost is always GatedGitClient. Its inner should be the FakeGitClient (no atomic wrap).
        $reflection = new \ReflectionObject($outer);
        $innerProp = $reflection->getProperty('inner');
        $innerProp->setAccessible(true);
        $inner = $innerProp->getValue($outer);

        $this->assertNotInstanceOf(AtomicCommittingGitClient::class, $inner);
        $this->assertSame($this->inner, $inner);
    }

    public function test_router_wraps_when_discipline_is_atomic(): void
    {
        $this->app->bind(GitHubApiClient::class, fn () => $this->inner);

        $router = new GitOperationRouter;
        $outer = $router->resolve($this->repo->fresh());

        $reflection = new \ReflectionObject($outer);
        $innerProp = $reflection->getProperty('inner');
        $innerProp->setAccessible(true);
        $inner = $innerProp->getValue($outer);

        $this->assertInstanceOf(AtomicCommittingGitClient::class, $inner);
    }
}

// -----------------------------------------------------------------------------
// Test doubles (kept in same file — only used by this test).
// -----------------------------------------------------------------------------

class FakeGitClient implements GitClientInterface
{
    /** @var list<array{path: string, content: string, message: string, branch: string}> */
    public array $writes = [];

    /** @var list<array{changes: array, message: string, branch: string}> */
    public array $commits = [];

    public string $fileContent = '';

    public function ping(): bool
    {
        return true;
    }

    public function readFile(string $path, string $ref = 'HEAD'): string
    {
        return $this->fileContent;
    }

    public function writeFile(string $path, string $content, string $message, string $branch): string
    {
        $this->writes[] = compact('path', 'content', 'message', 'branch');

        return 'sha-abc';
    }

    public function listFiles(string $path = '/', string $ref = 'HEAD'): array
    {
        return [];
    }

    public function getFileTree(string $ref = 'HEAD'): array
    {
        return [];
    }

    public function createBranch(string $branch, string $from): void {}

    public function commit(array $changes, string $message, string $branch): string
    {
        $this->commits[] = compact('changes', 'message', 'branch');

        return 'sha-batch';
    }

    public function push(string $branch): void {}

    public function createPullRequest(string $title, string $body, string $head, string $base): array
    {
        return ['pr_number' => '1', 'pr_url' => 'http://x', 'title' => $title, 'status' => 'open'];
    }

    public function listPullRequests(string $state = 'open'): array
    {
        return [];
    }

    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array
    {
        return ['sha' => 'm', 'merged' => true, 'message' => 'ok'];
    }

    public function getPullRequestStatus(int $prNumber): array
    {
        return ['mergeable' => true, 'ci_passing' => true, 'reviews_approved' => true, 'checks' => [], 'state' => 'open'];
    }

    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array
    {
        return ['dispatched' => true];
    }

    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array
    {
        return ['id' => 1, 'tag_name' => $tagName, 'name' => $name, 'url' => 'http://x', 'draft' => $draft, 'prerelease' => $prerelease];
    }

    public function closePullRequest(int $prNumber): void {}

    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array
    {
        return [];
    }
}

class RecordingGateway implements AiGatewayInterface
{
    /** @var list<AiRequestDTO> */
    public array $requests = [];

    public string $reply = 'chore: test';

    public ?\Throwable $throw = null;

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $this->requests[] = $request;

        if ($this->throw) {
            throw $this->throw;
        }

        return new AiResponseDTO(
            content: $this->reply,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 20, costCredits: 100),
            provider: $request->provider,
            model: $request->model,
            latencyMs: 10,
        );
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        return $this->complete($request);
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 100;
    }
}
