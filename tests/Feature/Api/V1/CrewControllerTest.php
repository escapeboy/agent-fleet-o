<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;

class CrewControllerTest extends ApiTestCase
{
    private function createAgent(array $overrides = []): Agent
    {
        return Agent::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Agent '.uniqid(),
            'slug' => 'agent-'.uniqid(),
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'status' => 'active',
            'config' => [],
            'capabilities' => [],
            'constraints' => [],
            'budget_spent_credits' => 0,
        ], $overrides));
    }

    private function createCrew(array $overrides = []): Crew
    {
        $coordinator = $overrides['coordinator_agent_id'] ?? $this->createAgent()->id;
        $qa = $overrides['qa_agent_id'] ?? $this->createAgent()->id;
        unset($overrides['coordinator_agent_id'], $overrides['qa_agent_id']);

        return Crew::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'coordinator_agent_id' => $coordinator,
            'qa_agent_id' => $qa,
            'name' => 'Test Crew',
            'slug' => 'test-crew-'.uniqid(),
            'process_type' => 'hierarchical',
            'max_task_iterations' => 3,
            'quality_threshold' => 0.70,
            'status' => 'active',
            'settings' => [],
        ], $overrides));
    }

    public function test_can_list_crews(): void
    {
        $this->actingAsApiUser();
        $this->createCrew(['name' => 'Crew One']);
        $this->createCrew(['name' => 'Crew Two']);

        $response = $this->getJson('/api/v1/crews');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'process_type']],
            ]);
    }

    public function test_can_filter_crews_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createCrew(['name' => 'Active', 'status' => 'active']);
        $this->createCrew(['name' => 'Draft', 'status' => 'draft']);

        $response = $this->getJson('/api/v1/crews?filter[status]=active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active');
    }

    public function test_can_show_crew(): void
    {
        $this->actingAsApiUser();
        $crew = $this->createCrew();

        $response = $this->getJson("/api/v1/crews/{$crew->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $crew->id)
            ->assertJsonPath('data.name', 'Test Crew');
    }

    public function test_can_create_crew(): void
    {
        $this->actingAsApiUser();
        $coordinator = $this->createAgent();
        $qa = $this->createAgent();

        $response = $this->postJson('/api/v1/crews', [
            'name' => 'New Crew',
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
            'process_type' => 'hierarchical',
            'max_task_iterations' => 5,
            'quality_threshold' => 0.80,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Crew')
            ->assertJsonPath('data.process_type', 'hierarchical');

        $this->assertDatabaseHas('crews', ['name' => 'New Crew']);
    }

    public function test_create_crew_requires_coordinator(): void
    {
        $this->actingAsApiUser();
        $qa = $this->createAgent();

        $response = $this->postJson('/api/v1/crews', [
            'name' => 'Missing Coordinator',
            'qa_agent_id' => $qa->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['coordinator_agent_id']);
    }

    public function test_can_update_crew(): void
    {
        $this->actingAsApiUser();
        $crew = $this->createCrew();

        $response = $this->putJson("/api/v1/crews/{$crew->id}", [
            'name' => 'Updated Crew',
            'max_task_iterations' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Crew');
    }

    public function test_can_delete_crew(): void
    {
        $this->actingAsApiUser();
        $crew = $this->createCrew();

        $response = $this->deleteJson("/api/v1/crews/{$crew->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Crew deleted.']);

        $this->assertSoftDeleted('crews', ['id' => $crew->id]);
    }

    public function test_can_list_crew_executions(): void
    {
        $this->actingAsApiUser();
        $crew = $this->createCrew();

        $response = $this->getJson("/api/v1/crews/{$crew->id}/executions");

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_unauthenticated_cannot_list_crews(): void
    {
        $response = $this->getJson('/api/v1/crews');

        $response->assertStatus(401);
    }
}
