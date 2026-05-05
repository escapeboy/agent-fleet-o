<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WellKnownFleetQTest extends TestCase
{
    use RefreshDatabase;

    public function test_discovery_endpoint_is_publicly_accessible(): void
    {
        $response = $this->getJson('/.well-known/fleetq');

        $response->assertOk();
    }

    public function test_returns_expected_structure(): void
    {
        $response = $this->getJson('/.well-known/fleetq');

        $response->assertJsonStructure([
            'version',
            'product',
            'description',
            'auth' => ['type', 'login_url', 'token_url', 'env', 'docs', 'instructions'],
            'endpoints' => ['mcp', 'api', 'bootstrap', 'openapi'],
            'capabilities' => ['mcp', 'mcp_transport', 'byok', 'codemode'],
            'docs' => ['mcp', 'api'],
        ]);
    }

    public function test_endpoints_are_absolute_urls(): void
    {
        config(['app.url' => 'https://fleetq.example.com']);

        $response = $this->getJson('/.well-known/fleetq');

        $data = $response->json();
        $this->assertSame('https://fleetq.example.com/mcp', $data['endpoints']['mcp']);
        $this->assertSame('https://fleetq.example.com/api/v1', $data['endpoints']['api']);
        $this->assertSame('https://fleetq.example.com/api/v1/me/bootstrap', $data['endpoints']['bootstrap']);
    }

    public function test_does_not_leak_secrets(): void
    {
        config(['app.url' => 'https://fleetq.example.com']);

        $response = $this->getJson('/.well-known/fleetq');

        $body = strtolower($response->getContent() ?: '');
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('api_key', $body);
        $this->assertStringNotContainsString('password', $body);
    }
}
