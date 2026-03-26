<?php

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Contracts\GitClientInterface;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\GitRepository\VersionBumpTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class VersionBumpToolTest extends TestCase
{
    use RefreshDatabase;

    private VersionBumpTool $tool;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(VersionBumpTool::class);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-version',
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

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($this->tool, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->tool, ...$args);
    }

    // -------------------------------------------------------------------------
    // Unit: increment()
    // -------------------------------------------------------------------------

    public function test_increment_patch(): void
    {
        $this->assertSame('1.2.4', $this->callPrivate('increment', '1.2.3', 'patch'));
    }

    public function test_increment_minor_resets_patch(): void
    {
        $this->assertSame('1.3.0', $this->callPrivate('increment', '1.2.3', 'minor'));
    }

    public function test_increment_major_resets_minor_and_patch(): void
    {
        $this->assertSame('2.0.0', $this->callPrivate('increment', '1.2.3', 'major'));
    }

    public function test_increment_strips_leading_v(): void
    {
        $this->assertSame('1.2.4', $this->callPrivate('increment', 'v1.2.3', 'patch'));
    }

    // -------------------------------------------------------------------------
    // Unit: updateVersion()
    // -------------------------------------------------------------------------

    public function test_update_version_in_package_json(): void
    {
        $json = json_encode(['name' => 'myapp', 'version' => '1.0.0', 'scripts' => []]);
        $result = $this->callPrivate('updateVersion', $json, '1.0.0', '1.1.0', 'package.json');

        $data = json_decode($result, true);
        $this->assertSame('1.1.0', $data['version']);
        $this->assertSame('myapp', $data['name']);
    }

    public function test_update_version_in_plain_version_file(): void
    {
        $content = "1.0.0\n";
        $result = $this->callPrivate('updateVersion', $content, '1.0.0', '1.1.0', 'VERSION');

        $this->assertSame("1.1.0\n", $result);
    }

    public function test_update_version_replaces_only_first_occurrence_in_plain_file(): void
    {
        // If the version number appears more than once (e.g. in a file that records history),
        // only the first standalone line should be replaced.
        $content = "1.0.0\nSee changelog: upgraded from 1.0.0 to present\n";
        $result = $this->callPrivate('updateVersion', $content, '1.0.0', '1.1.0', 'VERSION');

        $this->assertStringContainsString("1.1.0\n", $result);
        // The inline mention in the second line must not be touched
        $this->assertStringContainsString('1.0.0 to present', $result);
    }

    public function test_update_version_in_pyproject_toml(): void
    {
        $content = "[tool.poetry]\nname = \"myapp\"\nversion = \"1.0.0\"\n";
        $result = $this->callPrivate('updateVersion', $content, '1.0.0', '1.1.0', 'pyproject.toml');

        $this->assertStringContainsString('version = "1.1.0"', $result);
        $this->assertStringNotContainsString('version = "1.0.0"', $result);
    }

    // -------------------------------------------------------------------------
    // Integration: handle()
    // -------------------------------------------------------------------------

    public function test_handle_returns_error_when_repository_not_found(): void
    {
        $response = $this->tool->handle($this->request([
            'repository_id' => '00000000-0000-0000-0000-000000000000',
            'bump' => 'patch',
        ]));

        $this->assertTrue($response->isError());
    }

    public function test_handle_returns_error_when_explicit_bump_missing_version(): void
    {
        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'bump' => 'explicit',
        ]));

        $this->assertTrue($response->isError());
        $this->assertStringContainsString('explicit_version', (string) $response->content());
    }

    public function test_handle_bumps_patch_version_in_package_json(): void
    {
        $packageJson = json_encode(['name' => 'myapp', 'version' => '1.2.3']);

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('readFile')
            ->with('package.json')
            ->andReturn($packageJson);
        $client->expects('commit')
            ->withArgs(function (array $changes, string $message, string $branch): bool {
                $data = json_decode($changes[0]['content'], true);

                return $changes[0]['path'] === 'package.json'
                    && $data['version'] === '1.2.4'
                    && $branch === 'main';
            })
            ->andReturn('abc1234def5678');

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'bump' => 'patch',
            'branch' => 'main',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertTrue($data['success']);
        $this->assertSame('1.2.3', $data['previous_version']);
        $this->assertSame('1.2.4', $data['new_version']);
        $this->assertSame('abc1234def5678', $data['commit_sha']);
    }

    public function test_handle_sets_explicit_version(): void
    {
        $packageJson = json_encode(['name' => 'myapp', 'version' => '0.9.0']);

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('readFile')->andReturn($packageJson);
        $client->expects('commit')
            ->withArgs(function (array $changes): bool {
                $data = json_decode($changes[0]['content'], true);

                return $data['version'] === '2.0.0';
            })
            ->andReturn('deadbeef');

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'bump' => 'explicit',
            'explicit_version' => 'v2.0.0',
        ]));

        $this->assertFalse($response->isError());
        $data = $this->decode($response);
        $this->assertSame('2.0.0', $data['new_version']);
    }

    public function test_handle_uses_custom_commit_message(): void
    {
        $packageJson = json_encode(['name' => 'myapp', 'version' => '1.0.0']);

        $client = Mockery::mock(GitClientInterface::class);
        $client->expects('readFile')->andReturn($packageJson);
        $client->expects('commit')
            ->withArgs(function (array $changes, string $message): bool {
                return $message === 'release: 1.0.1';
            })
            ->andReturn('sha123');

        $router = Mockery::mock(GitOperationRouter::class);
        $router->expects('resolve')->andReturn($client);
        app()->instance(GitOperationRouter::class, $router);

        $response = $this->tool->handle($this->request([
            'repository_id' => $this->repo->id,
            'bump' => 'patch',
            'commit_message' => 'release: 1.0.1',
        ]));

        $this->assertFalse($response->isError());
    }
}
