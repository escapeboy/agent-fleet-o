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

    public function test_health_is_publicly_accessible(): void
    {
        // /health is a public endpoint used by deploy scripts — no auth required
        $response = $this->getJson('/api/v1/health');

        $response->assertJsonStructure(['status', 'checks']);
        $this->assertContains($response->status(), [200, 503]);
    }
}
