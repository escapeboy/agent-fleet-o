<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;

class OrchestrationApiTest extends ApiTestCase
{
    private function crew(): Crew
    {
        return Crew::factory()->create([
            'team_id' => $this->team->id,
            'max_task_iterations' => 3,
            'coordinator_agent_id' => Agent::factory()->create(['team_id' => $this->team->id])->id,
            'qa_agent_id' => Agent::factory()->create(['team_id' => $this->team->id])->id,
        ]);
    }

    public function test_crew_cost_estimate_endpoint(): void
    {
        $crew = $this->crew();

        $this->actingAsApiUser()
            ->getJson("/api/v1/crews/{$crew->id}/cost-estimate")
            ->assertOk()
            ->assertJsonPath('data.projected_credits', 44)
            ->assertJsonStructure(['data' => ['projected_credits', 'threshold_credits', 'gate_enabled', 'requires_confirmation']]);
    }

    public function test_execute_returns_402_when_cost_gate_trips(): void
    {
        config(['orchestration.cost_gate.enabled' => true, 'orchestration.cost_gate.threshold_credits' => 1]);
        $crew = $this->crew();

        $this->actingAsApiUser()
            ->postJson("/api/v1/crews/{$crew->id}/execute", ['goal' => 'do the thing'])
            ->assertStatus(402)
            ->assertJsonPath('error', 'cost_confirmation_required')
            ->assertJsonPath('threshold_credits', 1);
    }

    public function test_recommend_tier_is_404_when_disabled(): void
    {
        config(['orchestration.tier_selector.enabled' => false]);

        $this->actingAsApiUser()
            ->postJson('/api/v1/orchestration/recommend-tier', ['goal' => 'compare options'])
            ->assertNotFound();
    }

    public function test_recommend_tier_returns_recommendation_when_enabled(): void
    {
        config(['orchestration.tier_selector.enabled' => true]);

        $this->actingAsApiUser()
            ->postJson('/api/v1/orchestration/recommend-tier', ['goal' => 'Compare the top options and perspectives'])
            ->assertOk()
            ->assertJsonPath('data.tier', 'crew')
            ->assertJsonPath('data.process_type', 'fanout');
    }
}
