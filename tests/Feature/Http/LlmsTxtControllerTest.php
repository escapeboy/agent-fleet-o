<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

class LlmsTxtControllerTest extends TestCase
{
    // No RefreshDatabase — controller is pure render, no DB writes.

    public function test_compact_returns_200_with_markdown_content_type(): void
    {
        $response = $this->get('/llms.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
    }

    public function test_compact_body_contains_required_sections(): void
    {
        $response = $this->get('/llms.txt');

        $body = $response->getContent();

        $this->assertStringContainsString('# FleetQ', $body);
        $this->assertStringContainsString('## For agents', $body);
        $this->assertStringContainsString('## Capabilities', $body);
        $this->assertStringContainsString('mcp:start agent-fleet', $body);
        $this->assertStringContainsString('/.well-known/fleetq', $body);
        $this->assertStringContainsString('/llms-full.txt', $body);
    }

    public function test_compact_uses_configured_app_url(): void
    {
        config(['app.url' => 'https://fleetq.example.com']);

        $response = $this->get('/llms.txt');

        $body = $response->getContent();

        $this->assertStringContainsString('https://fleetq.example.com/mcp', $body);
        $this->assertStringContainsString('https://fleetq.example.com/.well-known/fleetq', $body);
        $this->assertStringContainsString('https://fleetq.example.com/llms-full.txt', $body);
    }

    public function test_full_returns_200_and_includes_compact_plus_capabilities(): void
    {
        $response = $this->get('/llms-full.txt');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');

        $body = $response->getContent();

        // Compact section header
        $this->assertStringContainsString('# FleetQ', $body);
        // Capabilities document header (from base/docs/capabilities.md)
        $this->assertStringContainsString('# FleetQ — Platform Capabilities for Coding Agents', $body);
    }

    public function test_endpoints_are_unauthenticated(): void
    {
        // No login, no team — both should still return 200
        $this->get('/llms.txt')->assertOk();
        $this->get('/llms-full.txt')->assertOk();
    }

    public function test_tool_count_is_a_positive_integer(): void
    {
        $response = $this->get('/llms.txt');

        $body = $response->getContent();

        // Compact body has "{N}+ MCP tools" where N is the count
        $this->assertMatchesRegularExpression('/- \d+\+ MCP tools/', $body);
    }
}
