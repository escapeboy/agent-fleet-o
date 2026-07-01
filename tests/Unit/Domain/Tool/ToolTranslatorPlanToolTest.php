<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Agent\Services\SandboxedWorkspace;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the `update_plan` planning tool (deepagents write_todos borrow):
 * flag gating, the rendered checklist, plan.md persistence, tolerant JSON
 * parsing, and the actionable error paths the model self-corrects from.
 */
class ToolTranslatorPlanToolTest extends TestCase
{
    use RefreshDatabase;

    private function planTool(): Tool
    {
        return Tool::factory()->create([
            'type' => ToolType::BuiltIn,
            'transport_config' => ['kind' => 'plan'],
        ]);
    }

    public function test_disabled_flag_returns_no_tool(): void
    {
        config(['agent.planning_tool.enabled' => false]);

        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool());

        $this->assertSame([], $tools);
    }

    public function test_enabled_flag_exposes_update_plan_tool(): void
    {
        config(['agent.planning_tool.enabled' => true]);

        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool());

        $this->assertCount(1, $tools);
        $this->assertSame('update_plan', $tools[0]->name());
    }

    public function test_renders_checklist_with_statuses_and_counts(): void
    {
        config(['agent.planning_tool.enabled' => true]);
        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool());

        $json = json_encode([
            ['content' => 'Scope the task', 'status' => 'completed'],
            ['content' => 'Write the code', 'status' => 'in_progress'],
            ['content' => 'Add tests', 'status' => 'pending'],
        ]);

        $out = $tools[0]->handle($json);

        $this->assertStringContainsString('Scope the task', $out);
        $this->assertStringContainsString('Write the code', $out);
        $this->assertStringContainsString('[x]', $out);
        $this->assertStringContainsString('[~]', $out);
        $this->assertStringContainsString('[ ]', $out);
        $this->assertStringContainsString('1 done, 1 in progress, 1 todo', $out);
    }

    public function test_persists_plan_md_to_sandbox_workspace(): void
    {
        config(['agent.planning_tool.enabled' => true]);

        $workspace = new SandboxedWorkspace(
            'exec-plan-test',
            'agent-x',
            'team-y',
            sys_get_temp_dir().'/fleetq-plan-test',
        );
        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool(), [], null, $workspace);

        $json = json_encode([['content' => 'Do the thing', 'status' => 'pending']]);
        $tools[0]->handle($json);

        $planPath = $workspace->resolve('plan.md');
        $this->assertFileExists($planPath);
        $this->assertStringContainsString('Do the thing', (string) file_get_contents($planPath));

        $workspace->teardown();
    }

    public function test_accepts_markdown_fenced_json(): void
    {
        config(['agent.planning_tool.enabled' => true]);
        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool());

        $fenced = "```json\n".json_encode([['content' => 'Fenced step', 'status' => 'pending']])."\n```";
        $out = $tools[0]->handle($fenced);

        $this->assertStringContainsString('Fenced step', $out);
        $this->assertStringNotContainsString('Error', $out);
    }

    public function test_malformed_json_returns_actionable_error(): void
    {
        config(['agent.planning_tool.enabled' => true]);
        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool());

        $out = $tools[0]->handle('not json at all');

        $this->assertStringContainsString('Error', $out);
        $this->assertStringContainsString('JSON array', $out);
    }

    public function test_invalid_status_returns_error(): void
    {
        config(['agent.planning_tool.enabled' => true]);
        $tools = app(ToolTranslator::class)->toPrismTools($this->planTool());

        $json = json_encode([['content' => 'Bad status item', 'status' => 'done']]);
        $out = $tools[0]->handle($json);

        $this->assertStringContainsString('Error', $out);
        $this->assertStringContainsString('Invalid status', $out);
    }
}
