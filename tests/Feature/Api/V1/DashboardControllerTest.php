<?php

namespace Tests\Feature\Api\V1;

class DashboardControllerTest extends ApiTestCase
{
    public function test_can_get_dashboard(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'experiments_count',
                    'active_experiments',
                    'agents_count',
                    'active_agents',
                    'skills_count',
                    'workflows_count',
                ],
            ]);
    }

    public function test_unauthenticated_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/v1/dashboard');

        $response->assertStatus(401);
    }
}
