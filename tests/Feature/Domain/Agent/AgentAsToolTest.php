<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AgentAsToolTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-aat',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test credit',
        ]);

        // Mock AI gateway to avoid DI resolution issues with LocalBridgeGateway
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: 'test response',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 10, costCredits: 1),
            provider: 'test',
            model: 'test-model',
            latencyMs: 100,
        ))->byDefault();
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    public function test_depth_guard_rejects_exceeding_max_depth(): void
    {
        config(['agent.max_agent_tool_depth' => 2]);

        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $result = app(ExecuteAgentAction::class)->execute(
            agent: $agent,
            input: ['task' => 'test', '_is_nested_call' => true, '_agent_tool_depth' => 3],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertNull($result['output']);
        $this->assertEquals('failed', $result['execution']->status);
        $this->assertStringContainsString('max nesting depth', $result['execution']->error_message);
    }

    public function test_depth_zero_does_not_trigger_guard(): void
    {
        config(['agent.max_agent_tool_depth' => 3]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => [],
        ]);

        // Depth 0 should NOT trigger the guard (execution fails later due to no skills/tools, not depth)
        $result = app(ExecuteAgentAction::class)->execute(
            agent: $agent,
            input: ['task' => 'test', '_is_nested_call' => true, '_agent_tool_depth' => 0],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        // Should fail because no skills/tools, NOT because of depth.
        // Always assert at least one outcome so the test is never marked risky.
        $this->assertStringNotContainsString(
            'nesting depth',
            $result['execution']->error_message ?? '',
        );
    }

    public function test_resolve_tools_builds_agent_tools_from_callable_ids(): void
    {
        $parentAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Orchestrator',
        ]);

        $callableAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Researcher',
            'role' => 'Research specialist',
            'goal' => 'Find information',
            'status' => AgentStatus::Active,
        ]);

        $parentAgent->update([
            'config' => ['callable_agent_ids' => [$callableAgent->id]],
        ]);

        $tools = app(ResolveAgentToolsAction::class)->execute($parentAgent);

        // Should have at least one tool with the callable agent's name
        $agentToolNames = collect($tools)->map(fn ($t) => $t->name())->filter(
            fn ($name) => str_starts_with($name, 'call_agent_'),
        );

        $this->assertNotEmpty($agentToolNames);
    }

    public function test_resolve_tools_skips_disabled_callable_agents(): void
    {
        $parentAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $disabledAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Disabled,
        ]);

        $parentAgent->update([
            'config' => ['callable_agent_ids' => [$disabledAgent->id]],
        ]);

        $tools = app(ResolveAgentToolsAction::class)->execute($parentAgent);

        $agentToolNames = collect($tools)->map(fn ($t) => $t->name())->filter(
            fn ($name) => str_starts_with($name, 'call_agent_'),
        );

        $this->assertEmpty($agentToolNames);
    }

    public function test_resolve_tools_does_not_inject_agent_tools_at_max_depth(): void
    {
        config(['agent.max_agent_tool_depth' => 2]);

        $parentAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $callableAgent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'status' => AgentStatus::Active,
        ]);

        $parentAgent->update([
            'config' => ['callable_agent_ids' => [$callableAgent->id]],
        ]);

        // At depth 2 (= max), should NOT inject agent tools
        $tools = app(ResolveAgentToolsAction::class)->execute($parentAgent, agentToolDepth: 2);

        $agentToolNames = collect($tools)->map(fn ($t) => $t->name())->filter(
            fn ($name) => str_starts_with($name, 'call_agent_'),
        );

        $this->assertEmpty($agentToolNames);
    }

    public function test_self_reference_blocked_by_api_validation(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'config' => ['callable_agent_ids' => [$agent->id]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['config.callable_agent_ids']);
    }

    public function test_empty_callable_ids_produces_no_agent_tools(): void
    {
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['callable_agent_ids' => []],
        ]);

        $tools = app(ResolveAgentToolsAction::class)->execute($agent);

        $agentToolNames = collect($tools)->map(fn ($t) => $t->name())->filter(
            fn ($name) => str_starts_with($name, 'call_agent_'),
        );

        $this->assertEmpty($agentToolNames);
    }

    public function test_internal_keys_stripped_from_external_input(): void
    {
        config(['agent.max_agent_tool_depth' => 2]);

        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        // External call (no _is_nested_call) with _agent_tool_depth should be stripped
        $result = app(ExecuteAgentAction::class)->execute(
            agent: $agent,
            input: ['task' => 'test', '_agent_tool_depth' => 99],
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        // Should NOT fail with depth error because _agent_tool_depth was stripped
        if ($result['output'] === null) {
            $this->assertStringNotContainsString('nesting depth', $result['execution']->error_message ?? '');
        } else {
            $this->assertTrue(true, 'Execution succeeded (depth key was stripped)');
        }
    }

    public function test_circular_reference_blocked_by_api_validation(): void
    {
        $agentA = Agent::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $agentB = Agent::factory()->create([
            'team_id' => $this->team->id,
            'config' => ['callable_agent_ids' => [$agentA->id]],
        ]);

        // Try to make A call B (B already calls A) — should be rejected
        $response = $this->putJson("/api/v1/agents/{$agentA->id}", [
            'config' => ['callable_agent_ids' => [$agentB->id]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['config.callable_agent_ids']);
    }

    public function test_cross_team_callable_agent_rejected(): void
    {
        $otherTeam = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team-xtn',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $otherAgent = Agent::factory()->create([
            'team_id' => $otherTeam->id,
            'status' => AgentStatus::Active,
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
        ]);

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'config' => ['callable_agent_ids' => [$otherAgent->id]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['config.callable_agent_ids.0']);
    }
}
