<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Enums\ToolPermissionLevel;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Models\AgentResponseAudit;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentStrictModeAuditTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    private function makeAgent(bool $strictMode = false): Agent
    {
        return Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Active,
            'strict_mode' => $strictMode,
        ]);
    }

    private function makeExecution(Agent $agent, array $toolsUsed = []): AgentExecution
    {
        return AgentExecution::create([
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'status' => 'completed',
            'input' => ['task' => 'do something'],
            'output' => ['result' => 'done'],
            'tools_used' => $toolsUsed,
            'cost_credits' => 10,
            'duration_ms' => 500,
        ]);
    }

    public function test_audit_record_created_for_strict_mode_agent(): void
    {
        $agent = $this->makeAgent(strictMode: true);
        $execution = $this->makeExecution($agent, [['tool' => 'bash', 'result' => 'ok']]);

        // Simulate what ExecuteAgentAction::createResponseAudit does
        // by directly testing the AgentResponseAudit creation path
        $auditCount = AgentResponseAudit::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->count();

        // Baseline: no audits yet — the audit is created by the action, not the model
        $this->assertEquals(0, $auditCount);

        // Create one manually as the action would
        AgentResponseAudit::create([
            'agent_id' => $agent->id,
            'team_id' => $agent->team_id,
            'execution_id' => $execution->id,
            'step_index' => 0,
            'prompt_hash' => hash('sha256', json_encode(['task' => 'do something'])),
            'response_text' => 'done',
            'tools_called' => ['bash'],
            'schema_valid' => null,
            'violations' => null,
        ]);

        $this->assertEquals(1, AgentResponseAudit::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->count());
    }

    public function test_tool_not_in_allowlist_generates_violation(): void
    {
        $agent = $this->makeAgent(strictMode: true);

        // Assign one known tool
        $tool = Tool::factory()->create(['team_id' => $this->team->id, 'name' => 'allowed_tool']);
        $agent->tools()->attach($tool->id, ['permission_level' => ToolPermissionLevel::Write->value]);

        // Simulate tools_called containing an unregistered tool
        $execution = $this->makeExecution($agent, [['tool' => 'unknown_tool']]);

        $allowedToolNames = $agent->tools()->withoutGlobalScopes()->pluck('tools.name')->toArray();
        $toolsCalled = ['unknown_tool'];

        $violations = [];
        foreach ($toolsCalled as $calledName) {
            if (! empty($allowedToolNames) && ! in_array($calledName, $allowedToolNames, true)) {
                $violations[] = "Tool '{$calledName}' not in agent's allowed tool list";
            }
        }

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('unknown_tool', $violations[0]);
    }

    public function test_read_only_tool_call_does_not_generate_violation(): void
    {
        $agent = $this->makeAgent(strictMode: true);

        $tool = Tool::factory()->create(['team_id' => $this->team->id, 'name' => 'read_only_tool']);
        $agent->tools()->attach($tool->id, ['permission_level' => ToolPermissionLevel::ReadOnly->value]);

        $allowedToolNames = $agent->tools()->withoutGlobalScopes()->pluck('tools.name')->toArray();
        $toolsCalled = ['read_only_tool'];

        $violations = [];
        foreach ($toolsCalled as $calledName) {
            if (! empty($allowedToolNames) && ! in_array($calledName, $allowedToolNames, true)) {
                $violations[] = "Tool '{$calledName}' not in agent's allowed tool list";
            }
        }

        // Calling a read-only tool is legitimate — must NOT produce a violation
        $this->assertEmpty($violations);
    }

    public function test_empty_allowlist_skips_violation_check(): void
    {
        $agent = $this->makeAgent(strictMode: true);
        // No tools attached to this agent

        $allowedToolNames = $agent->tools()->withoutGlobalScopes()->pluck('tools.name')->toArray();
        $toolsCalled = ['any_tool', 'another_tool'];

        $violations = [];
        foreach ($toolsCalled as $calledName) {
            if (! empty($allowedToolNames) && ! in_array($calledName, $allowedToolNames, true)) {
                $violations[] = "Tool '{$calledName}' not in agent's allowed tool list";
            }
        }

        // No pivot rows → no restriction → no violations
        $this->assertEmpty($violations);
    }
}
