<?php

namespace Tests\Feature\Mcp;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Testing\LintTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use ReflectionMethod;
use Tests\TestCase;

class LintToolTest extends TestCase
{
    use RefreshDatabase;

    private LintTool $tool;

    private Team $team;

    private GitRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = app(LintTool::class);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-lint',
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

    public function test_build_command_pint_test_mode(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'pint', '', false);
        $this->assertSame('vendor/bin/pint --test', $cmd);
    }

    public function test_build_command_pint_fix_mode(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'pint', '', true);
        $this->assertSame('vendor/bin/pint', $cmd);
    }

    public function test_build_command_phpstan_no_paths(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'phpstan', '', false);
        $this->assertSame('vendor/bin/phpstan analyse .', $cmd);
    }

    public function test_build_command_eslint_with_fix(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'eslint', '', true);
        $this->assertStringContainsString('--fix', $cmd);
    }

    public function test_build_command_black_check_mode(): void
    {
        $cmd = $this->callPrivate('buildCommand', 'black', '', false);
        $this->assertStringContainsString('--check', $cmd);
    }

    public function test_build_command_throws_for_unknown_linter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->callPrivate('buildCommand', 'rubocop', '', false);
    }

    public function test_multiple_paths_are_each_escaped(): void
    {
        // Space is the delimiter between paths; each token is escaped individually
        $cmd = $this->callPrivate('buildCommand', 'pint', 'src/ tests/', false);
        $this->assertStringContainsString("'src/'", $cmd);
        $this->assertStringContainsString("'tests/'", $cmd);
    }

    // -------------------------------------------------------------------------
    // parseOutput(): PHPStan / Pint
    // -------------------------------------------------------------------------

    public function test_parse_phpstan_output(): void
    {
        $output = "src/Foo.php:42:0: ERROR - Some error message\nsrc/Bar.php:10:0: ERROR - Another error";
        $issues = $this->callPrivate('parseOutput', $output, 'phpstan');

        $this->assertCount(2, $issues);
        $this->assertSame('src/Foo.php', $issues[0]['file']);
        $this->assertSame(42, $issues[0]['line']);
        $this->assertStringContainsString('Some error message', $issues[0]['message']);
    }

    public function test_parse_pint_output(): void
    {
        $output = "  ✗ src/Foo.php\n  ✗ src/Bar.php";
        $issues = $this->callPrivate('parseOutput', $output, 'pint');

        $this->assertCount(2, $issues);
        $this->assertNull($issues[0]['line']);
        $this->assertSame('Code style violation', $issues[0]['message']);
    }

    // -------------------------------------------------------------------------
    // parseOutput(): ESLint — file context tracking
    // -------------------------------------------------------------------------

    public function test_parse_eslint_output_tracks_file_per_issue(): void
    {
        $output = implode("\n", [
            '/app/src/foo.js',
            '  1:5  error  Missing semicolon  semi',
            '  3:1  warning  Unexpected var  no-var',
            '',
            '/app/src/bar.ts',
            '  10:3  error  Undefined variable  no-undef',
        ]);

        $issues = $this->callPrivate('parseOutput', $output, 'eslint');

        $this->assertCount(3, $issues);

        $this->assertSame('/app/src/foo.js', $issues[0]['file']);
        $this->assertSame(1, $issues[0]['line']);

        $this->assertSame('/app/src/foo.js', $issues[1]['file']);
        $this->assertSame(3, $issues[1]['line']);

        $this->assertSame('/app/src/bar.ts', $issues[2]['file']);
        $this->assertSame(10, $issues[2]['line']);
    }

    public function test_parse_eslint_issue_file_is_never_null(): void
    {
        // Without the file-header tracking fix, file would be null
        $output = "/app/src/foo.js\n  5:3  error  Some error  some-rule";
        $issues = $this->callPrivate('parseOutput', $output, 'eslint');

        $this->assertCount(1, $issues);
        $this->assertNotNull($issues[0]['file']);
        $this->assertSame('/app/src/foo.js', $issues[0]['file']);
    }

    // -------------------------------------------------------------------------
    // parseOutput(): flake8 / mypy
    // -------------------------------------------------------------------------

    public function test_parse_flake8_output(): void
    {
        $output = "src/foo.py:10:5: E501 line too long (120 > 79 characters)\nsrc/bar.py:3:1: F401 'os' imported but unused";
        $issues = $this->callPrivate('parseOutput', $output, 'flake8');

        $this->assertCount(2, $issues);
        $this->assertSame('src/foo.py', $issues[0]['file']);
        $this->assertSame(10, $issues[0]['line']);
    }

    public function test_parse_mypy_output(): void
    {
        $output = "src/foo.py:15: error: Incompatible types\nsrc/bar.py:7: warning: Unused variable";
        $issues = $this->callPrivate('parseOutput', $output, 'mypy');

        $this->assertCount(2, $issues);
        $this->assertStringContainsString('[error]', $issues[0]['message']);
        $this->assertStringContainsString('Incompatible types', $issues[0]['message']);
    }

    // -------------------------------------------------------------------------
    // detectFilesFixed()
    // -------------------------------------------------------------------------

    public function test_detect_files_fixed_pint_with_checkmark(): void
    {
        $result = $this->callPrivate('detectFilesFixed', "  ✓ app/Foo.php\n  ✓ app/Bar.php", 'pint');
        $this->assertTrue($result);
    }

    public function test_detect_files_fixed_pint_no_checkmark(): void
    {
        $result = $this->callPrivate('detectFilesFixed', 'No files changed.', 'pint');
        $this->assertFalse($result);
    }

    public function test_detect_files_fixed_black_with_reformatted(): void
    {
        $result = $this->callPrivate('detectFilesFixed', "reformatted src/foo.py\n1 file reformatted.", 'black');
        $this->assertTrue($result);
    }

    public function test_detect_files_fixed_black_nothing_reformatted(): void
    {
        $result = $this->callPrivate('detectFilesFixed', "All done! ✨ 🍰 ✨\n3 files would be left unchanged.", 'black');
        $this->assertFalse($result);
    }

    public function test_detect_files_fixed_prettier(): void
    {
        $output = "src/index.ts\nsrc/components/Button.tsx";
        $result = $this->callPrivate('detectFilesFixed', $output, 'prettier');
        $this->assertTrue($result);
    }
}
