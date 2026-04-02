<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Enums\AgentHookType;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentHook;
use App\Domain\Agent\Services\AgentHookExecutor;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentHookExecutorTest extends TestCase
{
    use RefreshDatabase;

    private AgentHookExecutor $executor;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new AgentHookExecutor;
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    public function test_returns_context_unchanged_when_no_hooks(): void
    {
        $context = ['input' => ['task' => 'test'], 'system_prompt' => 'You are helpful'];
        $result = $this->executor->run(AgentHookPosition::PreExecute, $this->agent, $context);

        $this->assertEquals($context, $result);
    }

    public function test_prompt_injection_hook_appends_to_system_prompt(): void
    {
        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'name' => 'Always translate',
            'position' => AgentHookPosition::PreExecute,
            'type' => AgentHookType::PromptInjection,
            'config' => ['text' => 'Always respond in German.', 'target' => 'system_prompt'],
            'priority' => 100,
            'enabled' => true,
        ]);

        $result = $this->executor->run(AgentHookPosition::PreExecute, $this->agent, [
            'input' => ['task' => 'hello'],
            'system_prompt' => 'You are helpful',
        ]);

        $this->assertStringContainsString('Always respond in German.', $result['system_prompt']);
    }

    public function test_guardrail_hook_cancels_execution(): void
    {
        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'name' => 'Block PII',
            'position' => AgentHookPosition::PreExecute,
            'type' => AgentHookType::Guardrail,
            'config' => ['rules' => [
                ['field' => 'input', 'operator' => 'contains', 'value' => 'password', 'message' => 'PII detected'],
            ]],
            'priority' => 50,
            'enabled' => true,
        ]);

        $result = $this->executor->run(AgentHookPosition::PreExecute, $this->agent, [
            'input' => 'my password is 123',
            'system_prompt' => '',
        ]);

        $this->assertTrue($result['cancel']);
        $this->assertEquals('PII detected', $result['cancel_reason']);
    }

    public function test_disabled_hooks_are_skipped(): void
    {
        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'name' => 'Disabled hook',
            'position' => AgentHookPosition::PreExecute,
            'type' => AgentHookType::PromptInjection,
            'config' => ['text' => 'SHOULD NOT APPEAR', 'target' => 'system_prompt'],
            'priority' => 100,
            'enabled' => false,
        ]);

        $result = $this->executor->run(AgentHookPosition::PreExecute, $this->agent, [
            'system_prompt' => 'original',
        ]);

        $this->assertEquals('original', $result['system_prompt']);
    }

    public function test_class_level_hooks_apply_to_all_agents(): void
    {
        // Class-level hook (agent_id = null)
        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => null,
            'name' => 'Team-wide disclaimer',
            'position' => AgentHookPosition::PreExecute,
            'type' => AgentHookType::PromptInjection,
            'config' => ['text' => 'Company policy applies.', 'target' => 'system_prompt'],
            'priority' => 10,
            'enabled' => true,
        ]);

        $result = $this->executor->run(AgentHookPosition::PreExecute, $this->agent, [
            'system_prompt' => 'Base prompt',
        ]);

        $this->assertStringContainsString('Company policy applies.', $result['system_prompt']);
    }

    public function test_hooks_execute_in_priority_order(): void
    {
        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'name' => 'Second',
            'position' => AgentHookPosition::PostExecute,
            'type' => AgentHookType::OutputTransform,
            'config' => ['transform' => 'suffix', 'suffix' => ' B'],
            'priority' => 200,
            'enabled' => true,
        ]);

        AgentHook::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'name' => 'First',
            'position' => AgentHookPosition::PostExecute,
            'type' => AgentHookType::OutputTransform,
            'config' => ['transform' => 'suffix', 'suffix' => ' A'],
            'priority' => 100,
            'enabled' => true,
        ]);

        $result = $this->executor->run(AgentHookPosition::PostExecute, $this->agent, [
            'output' => 'Hello',
        ]);

        $this->assertEquals('Hello A B', $result['output']);
    }
}
