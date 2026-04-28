<?php

namespace Tests\Feature\Api\V1;

class BroadcastingConfigControllerTest extends ApiTestCase
{
    public function test_returns_reverb_config_for_authenticated_caller(): void
    {
        config([
            'reverb.apps.apps.0.key' => 'fleetq-key',
            'reverb.apps.apps.0.options.host' => 'relay.fleetq.test',
            'reverb.apps.apps.0.options.port' => 443,
            'reverb.apps.apps.0.options.scheme' => 'https',
            'app.reverb_public_scheme' => null,
            'app.reverb_public_host' => null,
            'app.reverb_public_port' => null,
        ]);

        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/broadcasting/config');

        $response->assertOk()
            ->assertJsonPath('data.app_key', 'fleetq-key')
            ->assertJsonPath('data.host', 'relay.fleetq.test')
            ->assertJsonPath('data.port', 443)
            ->assertJsonPath('data.scheme', 'https');
    }

    public function test_prefers_public_facing_overrides_when_set(): void
    {
        // app.reverb_public_* is the production-deployment override that
        // hides internal Docker hosts in REVERB_HOST. When set, it wins.
        config([
            'reverb.apps.apps.0.key' => 'fleetq-key',
            'reverb.apps.apps.0.options.host' => 'reverb.internal',
            'reverb.apps.apps.0.options.port' => 8080,
            'reverb.apps.apps.0.options.scheme' => 'http',
            'app.reverb_public_scheme' => 'https',
            'app.reverb_public_host' => 'relay.fleetq.test',
            'app.reverb_public_port' => 443,
        ]);

        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/broadcasting/config');

        $response->assertOk()
            ->assertJsonPath('data.host', 'relay.fleetq.test')
            ->assertJsonPath('data.port', 443)
            ->assertJsonPath('data.scheme', 'https');
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/broadcasting/config');

        $response->assertUnauthorized();
    }
}
