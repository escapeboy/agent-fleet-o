<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Tool;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `run_tool_program` is late-bound: it must see the agent's OTHER resolved
 * tools only after ResolveAgentToolsAction has finished filtering.
 */
class ResolveAgentToolsProgramBindingTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent.programmatic_tool_calling.enabled' => true, 'agent.planning_tool.enabled' => true]);

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-resolve-program',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->agent = Agent::create([
            'id' => (string) Str::uuid7(),
            'team_id' => $this->team->id,
            'name' => 'Program Agent',
            'slug' => 'program-agent',
            'role' => 'assistant',
            'goal' => 'help',
            'backstory' => 'test',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet',
            'status' => AgentStatus::Active,
            'config' => [],
        ]);
    }

    private function attachBuiltIn(string $kind, string $approvalMode = 'auto'): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'type' => ToolType::BuiltIn,
            'status' => ToolStatus::Active,
            'transport_config' => ['kind' => $kind],
        ]);
        $this->agent->tools()->attach($tool->id, ['priority' => 0, 'approval_mode' => $approvalMode]);
    }

    public function test_approval_ask_tool_stays_direct_but_is_not_reachable_in_a_batch(): void
    {
        $this->attachBuiltIn('plan', approvalMode: 'ask');
        $this->attachBuiltIn('programmatic_tool_calling');

        $byName = collect(app(ResolveAgentToolsAction::class)->execute($this->agent))->keyBy(fn ($t) => $t->name());

        $this->assertTrue($byName->has('update_plan'), 'direct call path must be unchanged');

        $out = json_decode($byName['run_tool_program']->handle(json_encode([
            ['tool' => 'update_plan', 'arguments' => ['todos' => '[]']],
        ])), true);

        $this->assertFalse($out['calls'][0]['ok']);
        $this->assertStringContainsString('Unknown tool', $out['calls'][0]['error']);
        $this->assertNotContains('update_plan', $out['available_tools']);
    }

    public function test_denied_tool_is_not_reachable_in_a_batch(): void
    {
        $this->attachBuiltIn('plan', approvalMode: 'deny');
        $this->attachBuiltIn('programmatic_tool_calling');

        $byName = collect(app(ResolveAgentToolsAction::class)->execute($this->agent))->keyBy(fn ($t) => $t->name());

        $this->assertFalse($byName->has('update_plan'));
        $out = json_decode($byName['run_tool_program']->handle(json_encode([
            ['tool' => 'update_plan', 'arguments' => ['todos' => '[]']],
        ])), true);
        $this->assertFalse($out['calls'][0]['ok']);
    }

    public function test_program_tool_can_dispatch_to_sibling_resolved_tool(): void
    {
        $this->attachBuiltIn('plan');
        $this->attachBuiltIn('programmatic_tool_calling');

        $prismTools = app(ResolveAgentToolsAction::class)->execute($this->agent);
        $byName = collect($prismTools)->keyBy(fn ($t) => $t->name());

        $this->assertTrue($byName->has('update_plan'));
        $this->assertTrue($byName->has('run_tool_program'));

        $out = json_decode($byName['run_tool_program']->handle(json_encode([
            ['tool' => 'update_plan', 'arguments' => ['todos' => json_encode([['content' => 'x', 'status' => 'pending']])]],
        ])), true);

        $this->assertTrue($out['ok'], json_encode($out));
        $this->assertTrue($out['calls'][0]['ok'], json_encode($out));
        $this->assertStringContainsString('[ ] x', $out['calls'][0]['output']);
    }

    public function test_program_tool_without_siblings_reports_empty_availability(): void
    {
        $this->attachBuiltIn('programmatic_tool_calling');

        $prismTools = app(ResolveAgentToolsAction::class)->execute($this->agent);
        $program = collect($prismTools)->first(fn ($t) => $t->name() === 'run_tool_program');

        $out = json_decode($program->handle(json_encode([['tool' => 'update_plan', 'arguments' => []]])), true);

        $this->assertFalse($out['calls'][0]['ok']);
        $this->assertSame([], $out['available_tools']);
    }
}
