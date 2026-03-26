<?php

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Testing\TestRunnerTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use ReflectionMethod;
use Tests\TestCase;

class TestRunnerToolTest extends TestCase
{
    use RefreshDatabase;

    private TestRunnerTool $tool;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(TestRunnerTool::class);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-runner',
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

    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($this->tool, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->tool, ...$args);
    }

    // -------------------------------------------------------------------------
    // handle(): error cases
    // -------------------------------------------------------------------------

    public function test_handle_returns_error_when_repository_not_found(): void
    {
        $response = $this->tool->handle($this->request([
            'repository_id' => '00000000-0000-0000-0000-000000000000',
        ]));

        $this->assertTrue($response->isError());
    }

    // -------------------------------------------------------------------------
    // buildCommand()
    // -------------------------------------------------------------------------

    public function test_build_command_phpunit_no_filter(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'phpunit', null);
        $this->assertSame('vendor/bin/phpunit', $cmd);
    }

    public function test_build_command_phpunit_with_filter(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'phpunit', 'UserTest');
        $this->assertStringContainsString('--filter=', $cmd);
        $this->assertStringContainsString('UserTest', $cmd);
    }

    public function test_build_command_pest(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'pest', null);
        $this->assertSame('vendor/bin/pest', $cmd);
    }

    public function test_build_command_jest(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'jest', null);
        $this->assertSame('npx jest', $cmd);
    }

    public function test_build_command_pytest_with_filter(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'pytest', 'test_login');
        $this->assertStringContainsString('python -m pytest', $cmd);
        $this->assertStringContainsString('-k', $cmd);
        $this->assertStringContainsString('test_login', $cmd);
    }

    public function test_build_command_go(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'go', null);
        $this->assertSame('go test ./...', $cmd);
    }

    public function test_build_command_throws_for_unknown_framework(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callPrivate('buildCommand', 'mocha', null);
    }

    public function test_filter_is_shell_escaped(): void
    {
        // A filter with spaces/special chars must be properly quoted
        $cmd = $this->callPrivate('buildCommand', 'phpunit', "test name with 'quotes'");
        // Should NOT contain raw unescaped single-quotes from the filter
        $this->assertStringNotContainsString("--filter=test name with 'quotes'", $cmd);
    }

    // -------------------------------------------------------------------------
    // parseOutput()
    // -------------------------------------------------------------------------

    public function test_parse_phpunit_output_all_passing(): void
    {
        $output = "PHPUnit 11.0\n\n...\n\nTests: 42, Assertions: 156";
        $result = $this->callPrivate('parseOutput', $output, 'phpunit');

        $this->assertSame(42, $result['passed']);
        $this->assertNull($result['failed']);
        $this->assertNull($result['skipped']);
    }

    public function test_parse_phpunit_output_with_failures(): void
    {
        $output = "PHPUnit 11.0\n\nTests: 10, Assertions: 30, Failures: 2, Skipped: 1";
        $result = $this->callPrivate('parseOutput', $output, 'phpunit');

        $this->assertSame(7, $result['passed']); // 10 - 2 - 1
        $this->assertSame(2, $result['failed']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_parse_jest_output(): void
    {
        $output = "Tests: 3 failed, 37 passed, 40 total\nTest Suites: 1 failed, 5 passed, 6 total";
        $result = $this->callPrivate('parseOutput', $output, 'jest');

        $this->assertSame(37, $result['passed']);
        $this->assertSame(3, $result['failed']);
    }

    public function test_parse_pytest_output(): void
    {
        $output = '5 passed, 2 failed, 1 skipped in 3.42s';
        $result = $this->callPrivate('parseOutput', $output, 'pytest');

        $this->assertSame(5, $result['passed']);
        $this->assertSame(2, $result['failed']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_parse_go_output(): void
    {
        $output = "--- PASS: TestFoo (0.00s)\n--- PASS: TestBar (0.00s)\n--- FAIL: TestBaz (0.01s)\nok  \texample.com/pkg\t0.123s";
        $result = $this->callPrivate('parseOutput', $output, 'go');

        $this->assertSame(2, $result['passed']);
        $this->assertSame(1, $result['failed']);
    }

    public function test_parse_output_returns_nulls_for_unknown_framework(): void
    {
        $result = $this->callPrivate('parseOutput', 'some random output', 'custom');

        $this->assertNull($result['passed']);
        $this->assertNull($result['failed']);
        $this->assertNull($result['skipped']);
    }
}
