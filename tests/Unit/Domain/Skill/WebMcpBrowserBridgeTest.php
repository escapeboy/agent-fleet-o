<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Services\WebMcpBrowserBridge;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebMcpBrowserBridgeTest extends TestCase
{
    private WebMcpBrowserBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bridge = new WebMcpBrowserBridge;
    }

    public function test_is_enabled_returns_false_by_default(): void
    {
        Config::set('webmcp.agent_consumption.enabled', false);

        $this->assertFalse($this->bridge->isEnabled());
    }

    public function test_is_enabled_returns_true_when_configured(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);

        $this->assertTrue($this->bridge->isEnabled());
    }

    public function test_discover_tools_returns_empty_when_disabled(): void
    {
        Config::set('webmcp.agent_consumption.enabled', false);

        $result = $this->bridge->discoverTools('ws://localhost:3000/devtools/page/1');

        $this->assertSame([], $result);
    }

    public function test_discover_tools_returns_tools_on_success(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);
        Config::set('webmcp.agent_consumption.discovery_timeout_ms', 3000);

        $tools = [
            ['name' => 'search', 'description' => 'Search the page', 'inputSchema' => ['type' => 'object']],
        ];

        Http::fake([
            '*' => Http::response([
                'result' => [
                    'result' => [
                        'value' => json_encode($tools),
                    ],
                ],
            ]),
        ]);

        $result = $this->bridge->discoverTools('ws://localhost:3000/devtools/page/1');

        $this->assertCount(1, $result);
        $this->assertSame('search', $result[0]['name']);
    }

    public function test_discover_tools_returns_empty_on_http_failure(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);
        Config::set('webmcp.agent_consumption.discovery_timeout_ms', 3000);

        Http::fake([
            '*' => Http::response(null, 500),
        ]);

        $result = $this->bridge->discoverTools('ws://localhost:3000/devtools/page/1');

        $this->assertSame([], $result);
    }

    public function test_discover_tools_returns_empty_on_exception(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);
        Config::set('webmcp.agent_consumption.discovery_timeout_ms', 3000);

        Http::fake(fn () => throw new \RuntimeException('Connection refused'));

        $result = $this->bridge->discoverTools('ws://localhost:3000/devtools/page/1');

        $this->assertSame([], $result);
    }

    public function test_execute_tool_returns_error_when_disabled(): void
    {
        Config::set('webmcp.agent_consumption.enabled', false);

        $result = $this->bridge->executeTool('ws://localhost:3000/devtools/page/1', 'search', ['q' => 'test']);

        $this->assertFalse($result['success']);
        $this->assertSame('WebMCP agent consumption is disabled.', $result['error']);
    }

    public function test_execute_tool_returns_result_on_success(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);

        Http::fake([
            '*' => Http::response([
                'result' => [
                    'result' => [
                        'value' => json_encode(['success' => true, 'content' => ['items' => [1, 2, 3]]]),
                    ],
                ],
            ]),
        ]);

        $result = $this->bridge->executeTool('ws://localhost:3000/devtools/page/1', 'search', ['q' => 'test']);

        $this->assertTrue($result['success']);
        $this->assertSame(['items' => [1, 2, 3]], $result['content']);
    }

    public function test_execute_tool_returns_error_on_cdp_failure(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);

        Http::fake([
            '*' => Http::response(null, 502),
        ]);

        $result = $this->bridge->executeTool('ws://localhost:3000/devtools/page/1', 'search');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('CDP request failed', $result['error']);
    }

    public function test_execute_tool_returns_error_on_exception(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);

        Http::fake(fn () => throw new \RuntimeException('Timeout'));

        $result = $this->bridge->executeTool('ws://localhost:3000/devtools/page/1', 'search');

        $this->assertFalse($result['success']);
        $this->assertSame('Timeout', $result['error']);
    }

    public function test_execute_tool_escapes_tool_name_to_prevent_js_injection(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);

        Http::fake([
            '*' => Http::response([
                'result' => [
                    'result' => [
                        'value' => json_encode(['success' => false, 'error' => 'Tool not found: evil\'tool']),
                    ],
                ],
            ]),
        ]);

        // Tool name with JS injection attempt — should be safely escaped
        $result = $this->bridge->executeTool(
            'ws://localhost:3000/devtools/page/1',
            "evil'; document.cookie; '",
            ['key' => 'value'],
        );

        // Verify the HTTP request was made (no PHP error from json_encode)
        Http::assertSent(function ($request) {
            $expression = $request['params']['expression'] ?? '';
            // The tool name must appear as a JSON-encoded string, not raw interpolation
            $this->assertStringContainsString('const toolName = "evil', $expression);
            $this->assertStringNotContainsString("=== 'evil'", $expression);

            return true;
        });
    }

    public function test_execute_tool_escapes_params_to_prevent_js_injection(): void
    {
        Config::set('webmcp.agent_consumption.enabled', true);

        Http::fake([
            '*' => Http::response([
                'result' => ['result' => ['value' => json_encode(['success' => true, 'content' => null])]],
            ]),
        ]);

        $this->bridge->executeTool(
            'ws://localhost:3000/devtools/page/1',
            'safe_tool',
            ['payload' => '"; alert(1); "'],
        );

        Http::assertSent(function ($request) {
            $expression = $request['params']['expression'] ?? '';
            // Params must be JSON-encoded, not raw interpolated
            $this->assertStringContainsString('const params = {', $expression);

            return true;
        });
    }
}
