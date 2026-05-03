<?php

namespace Tests\Feature\Tool;

use App\Domain\Agent\Services\DockerSandboxExecutor;
use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\BuiltInToolKind;
use App\Domain\Tool\Models\Toolset;
use App\Domain\Tool\Services\BuiltIn\BrowserHarnessHandler;
use App\Mcp\Tools\Tool\BrowserHarnessRunTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Browser harness tool (build #4, Trendshift top-5 sprint).
 */
class BrowserHarnessHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private FakeSandboxExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        User::factory()->create(['current_team_id' => $this->team->id]);
        app()->instance('mcp.team_id', $this->team->id);

        $this->executor = new FakeSandboxExecutor;
        $this->app->instance(DockerSandboxExecutor::class, $this->executor);
    }

    public function test_built_in_tool_kind_enum_includes_browser_harness(): void
    {
        $this->assertSame(
            'Browser Harness (self-healing CDP)',
            BuiltInToolKind::BrowserHarness->label(),
        );
    }

    public function test_handler_runs_a_task_via_sandbox_executor(): void
    {
        $handler = app(BrowserHarnessHandler::class);
        $result = $handler->execute(
            params: ['task' => 'go to example.com and screenshot'],
            teamId: $this->team->id,
        );

        $this->assertTrue($result['ok'] ?? false);
        $this->assertCount(1, $this->executor->calls);
        $this->assertStringContainsString('chromium', $this->executor->calls[0]['command']);
        $this->assertStringContainsString('helpers.py', $this->executor->calls[0]['command']);
        $this->assertFalse($result['persisted_pending']);
    }

    public function test_handler_appends_helpers_diff_into_helpers_py(): void
    {
        $handler = app(BrowserHarnessHandler::class);
        $handler->execute(
            params: [
                'task' => 'use my new helper',
                'helpers_diff' => "def custom_helper():\n    print('hi from agent')",
            ],
            teamId: $this->team->id,
        );

        $this->assertStringContainsString('Agent-added helpers', $this->executor->calls[0]['command']);
        $this->assertStringContainsString('custom_helper', $this->executor->calls[0]['command']);
    }

    public function test_handler_loads_approved_helpers_from_toolset(): void
    {
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'My Browser Helpers',
            'slug' => 'my-browser-helpers',
            'tool_ids' => [],
            'browser_helpers' => [
                'helpers' => [
                    ['name' => 'login_to_app', 'code' => "def login_to_app():\n    return 'ok'", 'approved' => true],
                    ['name' => 'unsafe_one', 'code' => "def unsafe():\n    return 'no'", 'approved' => false],
                ],
            ],
        ]);

        $handler = app(BrowserHarnessHandler::class);
        $handler->execute(
            params: ['task' => 'log in', 'toolset_id' => $toolset->id],
            teamId: $this->team->id,
        );

        $cmd = $this->executor->calls[0]['command'];
        $this->assertStringContainsString('Approved helpers from Toolset', $cmd);
        $this->assertStringContainsString('login_to_app', $cmd);
        $this->assertStringNotContainsString('unsafe_one', $cmd);
    }

    public function test_persist_helpers_true_stages_pending_review(): void
    {
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'Helpers',
            'slug' => 'helpers',
            'tool_ids' => [],
        ]);

        $handler = app(BrowserHarnessHandler::class);
        $result = $handler->execute(
            params: [
                'task' => 'do thing',
                'helpers_diff' => "def thing():\n    pass",
                'persist_helpers' => true,
                'toolset_id' => $toolset->id,
            ],
            teamId: $this->team->id,
        );

        $toolset->refresh();
        $this->assertTrue($result['persisted_pending']);
        $this->assertTrue($toolset->browser_helpers_pending_review);
        $this->assertCount(1, $toolset->browser_helpers['helpers']);
        $this->assertFalse($toolset->browser_helpers['helpers'][0]['approved']);
    }

    public function test_persist_helpers_false_does_not_modify_toolset(): void
    {
        $toolset = Toolset::create([
            'team_id' => $this->team->id,
            'name' => 'Helpers',
            'slug' => 'helpers',
            'tool_ids' => [],
        ]);

        app(BrowserHarnessHandler::class)->execute(
            params: [
                'task' => 'do thing',
                'helpers_diff' => "def thing():\n    pass",
                'persist_helpers' => false,
                'toolset_id' => $toolset->id,
            ],
            teamId: $this->team->id,
        );

        $toolset->refresh();
        $this->assertFalse($toolset->browser_helpers_pending_review);
        $this->assertNull($toolset->browser_helpers);
    }

    public function test_mcp_tool_rejects_toolset_id_from_other_team(): void
    {
        $otherTeam = Team::factory()->create();
        $foreignToolset = Toolset::create([
            'team_id' => $otherTeam->id,
            'name' => 'Foreign',
            'slug' => 'foreign',
            'tool_ids' => [],
        ]);

        $tool = app(BrowserHarnessRunTool::class);
        $request = new Request([
            'task' => 'should be blocked',
            'toolset_id' => $foreignToolset->id,
        ]);

        $this->expectException(ValidationException::class);
        $tool->handle($request);
    }

    public function test_mcp_tool_rejects_blank_task(): void
    {
        $tool = app(BrowserHarnessRunTool::class);
        $request = new Request(['task' => 'tiny']);

        $this->expectException(ValidationException::class);
        $tool->handle($request);
    }
}

// -----------------------------------------------------------------------------
// Fake — captures command + workspace, no actual docker exec.
// -----------------------------------------------------------------------------

class FakeSandboxExecutor extends DockerSandboxExecutor
{
    /** @var list<array{command: string, workspace: SandboxedWorkspace, timeout: int}> */
    public array $calls = [];

    public function __construct() {}

    public function execute(
        string $command,
        SandboxedWorkspace $workspace,
        int $timeoutSeconds = 30,
        ?array $env = null,
        ?array $networkPolicy = null,
    ): array {
        $this->calls[] = compact('command', 'workspace') + ['timeout' => $timeoutSeconds];

        return ['ok' => true, 'output' => '{"ok": true}', 'exit_code' => 0];
    }
}
