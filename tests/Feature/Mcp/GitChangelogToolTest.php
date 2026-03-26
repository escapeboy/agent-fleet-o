<?php

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\GitRepository\GitChangelogTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Mockery;
use Tests\TestCase;

class GitChangelogToolTest extends TestCase
{
    use RefreshDatabase;

    private GitChangelogTool $tool;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(GitChangelogTool::class);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-changelog',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        app()->instance('mcp.team_id', $this->team->id);

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

    private function request(array $args): Request
    {
        return new Request($args);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_handle_returns_error_when_repository_not_found(): void
    {
        $response = $this->tool->handle($this->request([
            'repository_id' => '00000000-0000-0000-0000-000000000000',
            'version' => 'v1.0.0',
        ]));

        $this->assertTrue($response->isError());
    }

    public function test_handle_returns_empty_changelog_when_no_commits(): void
    {
        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('getCommitLog')->andReturn([]);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'version' => 'v1.0.0',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertSame(0, $data['commit_count']);
        $this->assertStringContainsString('No changes found', $data['markdown']);
    }

    public function test_features_group_sorts_before_bug_fixes(): void
    {
        $commits = [
            ['sha' => 'aabbccdd', 'message' => 'fix: correct null pointer', 'author' => 'Alice', 'date' => '2026-01-01'],
            ['sha' => 'eeff0011', 'message' => 'feat: add dark mode', 'author' => 'Bob', 'date' => '2026-01-02'],
            ['sha' => '22334455', 'message' => 'chore: update deps', 'author' => 'Carol', 'date' => '2026-01-03'],
        ];

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('getCommitLog')->andReturn($commits);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'version' => 'v1.1.0',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $markdown = $data['markdown'];

        // Features must appear before Bug Fixes
        $this->assertLessThan(
            strpos($markdown, '### Bug Fixes'),
            strpos($markdown, '### Features'),
            'Features section should appear before Bug Fixes in the changelog',
        );

        // Chores must appear after both Features and Bug Fixes
        $this->assertGreaterThan(
            strpos($markdown, '### Bug Fixes'),
            strpos($markdown, '### Chores'),
        );
    }

    public function test_conventional_commit_scope_is_bolded_in_markdown(): void
    {
        $commits = [
            ['sha' => 'abc12345', 'message' => 'feat(auth): add SSO login', 'author' => 'Dev', 'date' => '2026-01-01'],
        ];

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('getCommitLog')->andReturn($commits);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'version' => 'v1.0.0',
        ]));

        $data = $this->decode($response);
        $this->assertStringContainsString('**auth**', $data['markdown']);
        $this->assertStringContainsString('add SSO login', $data['markdown']);
    }

    public function test_include_authors_appends_name_to_entry(): void
    {
        $commits = [
            ['sha' => 'abc12345', 'message' => 'feat: add feature', 'author' => 'Alice', 'date' => '2026-01-01'],
        ];

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('getCommitLog')->andReturn($commits);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'version' => 'v1.0.0',
            'include_authors' => true,
        ]));

        $data = $this->decode($response);
        $this->assertStringContainsString('Alice', $data['markdown']);
    }

    public function test_unrecognised_commit_falls_back_to_other_group(): void
    {
        $commits = [
            ['sha' => 'abc12345', 'message' => 'some free-form commit message', 'author' => 'Dev', 'date' => '2026-01-01'],
        ];

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('getCommitLog')->andReturn($commits);

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'version' => 'v1.0.0',
        ]));

        $data = $this->decode($response);
        $this->assertStringContainsString('### Other', $data['markdown']);
        $this->assertStringContainsString('some free-form commit message', $data['markdown']);
    }
}
