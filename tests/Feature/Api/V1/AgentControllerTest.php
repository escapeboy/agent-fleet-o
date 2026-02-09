<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\Agent;

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
}
