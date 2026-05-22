<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Services\ProviderResolver;

class AgentControllerTest extends ApiTestCase
{
    private function createAgent(array $overrides = []): Agent
    {
        return Agent::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Agent',
            'slug' => 'test-agent',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
            'config' => [],
            'capabilities' => [],
            'constraints' => [],
            'budget_spent_credits' => 0,
        ], $overrides));
    }

    public function test_can_list_agents(): void
    {
        $this->actingAsApiUser();
        $this->createAgent(['name' => 'Agent One', 'slug' => 'agent-one']);
        $this->createAgent(['name' => 'Agent Two', 'slug' => 'agent-two']);

        $response = $this->getJson('/api/v1/agents');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'provider', 'model']],
            ]);
    }

    public function test_can_filter_agents_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createAgent(['name' => 'Active', 'slug' => 'active', 'status' => 'active']);
        $this->createAgent(['name' => 'Disabled', 'slug' => 'disabled', 'status' => 'disabled']);

        $response = $this->getJson('/api/v1/agents?filter[status]=active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active');
    }

    public function test_can_show_agent(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $agent->id)
            ->assertJsonPath('data.name', 'Test Agent');
    }

    public function test_can_create_agent(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'New Agent',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'role' => 'Analyst',
            'goal' => 'Analyze data',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Agent')
            ->assertJsonPath('data.provider', 'openai');

        $this->assertDatabaseHas('agents', ['name' => 'New Agent']);
    }

    public function test_create_agent_without_provider_uses_resolved_default(): void
    {
        $this->actingAsApiUser();

        $resolved = app(ProviderResolver::class)->resolve(team: $this->team);

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Defaulted Agent',
            'role' => 'Analyst',
            'goal' => 'Analyze data',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.provider', $resolved['provider'])
            ->assertJsonPath('data.model', $resolved['model']);

        $this->assertDatabaseHas('agents', [
            'name' => 'Defaulted Agent',
            'provider' => $resolved['provider'],
            'model' => $resolved['model'],
        ]);
    }

    public function test_can_update_agent(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'name' => 'Updated Agent',
            'goal' => 'Updated goal',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Agent')
            ->assertJsonPath('data.goal', 'Updated goal');
    }

    public function test_can_delete_agent(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();

        $response = $this->deleteJson("/api/v1/agents/{$agent->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Agent deleted.']);

        $this->assertSoftDeleted('agents', ['id' => $agent->id]);
    }

    public function test_can_toggle_agent_status(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent(['status' => 'active']);

        $response = $this->patchJson("/api/v1/agents/{$agent->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.status', 'disabled');
    }

    public function test_unauthenticated_cannot_list_agents(): void
    {
        $response = $this->getJson('/api/v1/agents');

        $response->assertStatus(401);
    }

    public function test_can_create_agent_with_environment_and_reasoning_effort(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Coder',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'environment' => 'coding',
            'config' => ['reasoning_effort' => 'auto'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Coder');

        $agent = Agent::where('name', 'Coder')->first();
        $this->assertNotNull($agent);
        $this->assertSame('coding', $agent->environment?->value);
        $this->assertSame('auto', $agent->config['reasoning_effort'] ?? null);
    }

    public function test_rejects_invalid_environment_value(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Bad',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'environment' => 'EXTREME',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['environment']);
    }

    public function test_rejects_invalid_reasoning_effort_value(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Bad',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'config' => ['reasoning_effort' => 'extreme'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['config.reasoning_effort']);
    }

    public function test_can_update_environment_and_reasoning_effort(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'environment' => 'browsing',
            'config' => ['reasoning_effort' => 'high'],
        ]);

        $response->assertOk();

        $agent->refresh();
        $this->assertSame('browsing', $agent->environment?->value);
        $this->assertSame('high', $agent->config['reasoning_effort'] ?? null);
    }

    public function test_can_create_agent_with_tool_search_config(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Auto-Tool Agent',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'config' => [
                'use_tool_search' => true,
                'tool_search_top_k' => 8,
            ],
        ]);

        $response->assertStatus(201);

        $agent = Agent::where('name', 'Auto-Tool Agent')->first();
        $this->assertTrue($agent->config['use_tool_search'] ?? false);
        $this->assertSame(8, $agent->config['tool_search_top_k'] ?? null);
    }

    public function test_rejects_tool_search_top_k_out_of_range(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/agents', [
            'name' => 'Bad K',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'config' => ['tool_search_top_k' => 999],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['config.tool_search_top_k']);
    }

    public function test_resource_exposes_environment_and_tool_profile_fields(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent([
            'environment' => 'coding',
            'tool_profile' => 'researcher',
            'config' => ['reasoning_effort' => 'auto'],
        ]);

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

        $response->assertOk()
            ->assertJsonPath('data.environment', 'coding')
            ->assertJsonPath('data.tool_profile', 'researcher')
            ->assertJsonPath('data.config.reasoning_effort', 'auto');
    }

    public function test_show_agent_returns_attached_tools(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();
        $tool = Tool::factory()->create(['team_id' => $this->team->id]);
        $agent->tools()->attach($tool->id, ['priority' => 0]);

        $response = $this->getJson("/api/v1/agents/{$agent->id}");

        $response->assertOk()
            ->assertJsonCount(1, 'data.tools')
            ->assertJsonPath('data.tools.0.id', $tool->id)
            ->assertJsonPath('data.tools.0.name', $tool->name)
            ->assertJsonPath('data.tools.0.slug', $tool->slug);
    }

    public function test_update_agent_with_tool_ids_replaces_pivot(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();
        $existing = Tool::factory()->create(['team_id' => $this->team->id]);
        $agent->tools()->attach($existing->id, ['priority' => 0]);

        $replacement = Tool::factory()->create(['team_id' => $this->team->id]);

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'tool_ids' => [$replacement->id],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data.tools')
            ->assertJsonPath('data.tools.0.id', $replacement->id);

        $this->assertDatabaseHas('agent_tool', [
            'agent_id' => $agent->id,
            'tool_id' => $replacement->id,
        ]);
        $this->assertDatabaseMissing('agent_tool', [
            'agent_id' => $agent->id,
            'tool_id' => $existing->id,
        ]);
    }

    public function test_update_agent_rejects_unknown_tool_id(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'tool_ids' => ['00000000-0000-0000-0000-000000000000'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tool_ids.0']);
    }

    public function test_update_agent_rejects_tool_from_other_team(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();

        $otherTeam = Team::factory()->create();
        $foreignTool = Tool::factory()->create(['team_id' => $otherTeam->id]);

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'tool_ids' => [$foreignTool->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tool_ids.0']);

        $this->assertDatabaseMissing('agent_tool', [
            'agent_id' => $agent->id,
            'tool_id' => $foreignTool->id,
        ]);
    }

    public function test_update_agent_preserves_pivot_config_for_retained_tools(): void
    {
        $this->actingAsApiUser();
        $agent = $this->createAgent();
        $retained = Tool::factory()->create(['team_id' => $this->team->id]);
        $agent->tools()->attach($retained->id, [
            'priority' => 5,
            'approval_mode' => 'ask',
            'permission_level' => 'read_only',
        ]);

        $added = Tool::factory()->create(['team_id' => $this->team->id]);

        $response = $this->putJson("/api/v1/agents/{$agent->id}", [
            'tool_ids' => [$retained->id, $added->id],
        ]);

        $response->assertOk()
            ->assertJsonCount(2, 'data.tools');

        $this->assertDatabaseHas('agent_tool', [
            'agent_id' => $agent->id,
            'tool_id' => $retained->id,
            'priority' => 5,
            'approval_mode' => 'ask',
            'permission_level' => 'read_only',
        ]);
    }
}
