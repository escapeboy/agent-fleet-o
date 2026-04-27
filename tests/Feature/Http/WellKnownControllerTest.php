<?php

namespace Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WellKnownControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_200_without_authentication(): void
    {
        $response = $this->getJson('/.well-known/fleetq');

        $response->assertOk();
    }

    public function test_payload_contains_required_fields(): void
    {
        config([
            'discovery.expose_name' => true,
            'discovery.expose_version' => true,
            'discovery.expose_mcp' => true,
            'discovery.expose_api' => true,
            'discovery.expose_auth' => true,
            'discovery.expose_tool_count' => true,
            'discovery.expose_generated_at' => true,
        ]);

        $response = $this->getJson('/.well-known/fleetq');

        $response->assertOk();
        $response->assertJsonStructure([
            'name',
            'version',
            'mcp' => ['http_endpoint', 'stdio_command'],
            'api' => ['base_url', 'docs_url'],
            'auth' => ['scheme', 'token_endpoint'],
            'tools' => ['count'],
            'generated_at',
        ]);

        $data = $response->json();
        $this->assertSame('bearer', $data['auth']['scheme']);
        $this->assertSame('php artisan mcp:start agent-fleet', $data['mcp']['stdio_command']);
        $this->assertIsInt($data['tools']['count']);
        $this->assertGreaterThan(0, $data['tools']['count']);
    }

    public function test_tool_count_is_omitted_when_disabled(): void
    {
        config(['discovery.expose_tool_count' => false]);

        $response = $this->getJson('/.well-known/fleetq');

        $response->assertOk();
        $this->assertArrayNotHasKey('tools', $response->json());
    }

    public function test_version_is_omitted_when_disabled(): void
    {
        config(['discovery.expose_version' => false]);

        $response = $this->getJson('/.well-known/fleetq');

        $response->assertOk();
        $this->assertArrayNotHasKey('version', $response->json());
    }

    public function test_api_block_uses_configured_app_url(): void
    {
        config(['app.url' => 'https://fleetq.example.com']);

        $response = $this->getJson('/.well-known/fleetq');

        $data = $response->json();
        $this->assertSame('https://fleetq.example.com/api/v1', $data['api']['base_url']);
        $this->assertSame('https://fleetq.example.com/mcp', $data['mcp']['http_endpoint']);
    }
}
