<?php

namespace Tests\Feature\Api\V1;

class HealthControllerTest extends ApiTestCase
{
    public function test_can_check_health(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/health');

        // Database should be OK (SQLite), Redis may fail in test env
        $response->assertJsonStructure([
            'status',
            'checks' => ['database'],
        ]);
    }

    public function test_unauthenticated_cannot_check_health(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(401);
    }
}
