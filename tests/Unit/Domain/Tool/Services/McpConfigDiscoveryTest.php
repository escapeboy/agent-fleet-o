<?php

namespace Tests\Unit\Domain\Tool\Services;

use App\Domain\Tool\Services\McpConfigDiscovery;
use App\Domain\Tool\Services\McpConfigNormalizer;
use Tests\TestCase;

class McpConfigDiscoveryTest extends TestCase
{
    private McpConfigDiscovery $discovery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->discovery = new McpConfigDiscovery(new McpConfigNormalizer);

        // Ensure tests run in native mode (not bridge)
        putenv('RUNNING_IN_DOCKER=false');
        config(['local_agents.bridge.secret' => '']);
    }

    protected function tearDown(): void
    {
        putenv('RUNNING_IN_DOCKER');
        parent::tearDown();
    }

    // --- parseJsonInput ---

    public function test_parse_json_input_with_mcp_servers_key(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/claude_desktop.json');

        $servers = $this->discovery->parseJsonInput($json, 'Claude Desktop');

        $this->assertCount(2, $servers);
        $this->assertEquals('Filesystem', $servers[0]['name']);
        $this->assertEquals('Github', $servers[1]['name']);
    }

    public function test_parse_json_input_with_servers_key(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/vscode.json');

        $servers = $this->discovery->parseJsonInput($json, 'VS Code');

        $this->assertCount(1, $servers);
        $this->assertEquals('My Mcp Server', $servers[0]['name']);
        $this->assertEquals('mcp_stdio', $servers[0]['type']);
    }

    public function test_parse_json_input_extracts_credentials(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/claude_desktop.json');

        $servers = $this->discovery->parseJsonInput($json, 'Claude Desktop');

        // filesystem has no sensitive env vars (NODE_ENV is not sensitive)
        $this->assertEmpty($servers[0]['credentials']);

        // github has GITHUB_TOKEN
        $this->assertArrayHasKey('env_GITHUB_TOKEN', $servers[1]['credentials']);
    }

    public function test_parse_json_input_handles_http_servers(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/http_server.json');

        $servers = $this->discovery->parseJsonInput($json, 'Manual Import');

        $this->assertCount(1, $servers);
        $this->assertEquals('mcp_http', $servers[0]['type']);
        $this->assertEquals('https://mcp.example.com/sse', $servers[0]['transport_config']['url']);
        $this->assertArrayHasKey('api_key', $servers[0]['credentials']);
    }

    public function test_parse_json_input_handles_disabled_servers(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/disabled_server.json');

        $servers = $this->discovery->parseJsonInput($json, 'Test');

        $this->assertCount(1, $servers);
        $this->assertTrue($servers[0]['disabled']);
    }

    public function test_parse_json_input_returns_empty_for_malformed(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/malformed.json');

        $servers = $this->discovery->parseJsonInput($json, 'Test');

        $this->assertEmpty($servers);
    }

    public function test_parse_json_input_handles_minimal_config(): void
    {
        $json = file_get_contents(__DIR__.'/../../../../fixtures/mcp-configs/minimal.json');

        $servers = $this->discovery->parseJsonInput($json, 'Test');

        $this->assertCount(1, $servers);
        $this->assertEquals('Simple Server', $servers[0]['name']);
        $this->assertEquals('mcp_stdio', $servers[0]['type']);
        $this->assertEquals('python3', $servers[0]['transport_config']['command']);
        $this->assertEmpty($servers[0]['credentials']);
        $this->assertEmpty($servers[0]['warnings']);
    }

    // --- parseConfigFile ---

    public function test_parse_config_file_with_real_fixture(): void
    {
        $path = __DIR__.'/../../../../fixtures/mcp-configs/claude_desktop.json';

        $servers = $this->discovery->parseConfigFile($path, 'mcpServers', 'Claude Desktop');

        $this->assertCount(2, $servers);
        $this->assertEquals('filesystem-claude-desktop', $servers[0]['slug']);
        $this->assertEquals('github-claude-desktop', $servers[1]['slug']);
    }

    public function test_parse_config_file_returns_empty_for_missing_file(): void
    {
        $servers = $this->discovery->parseConfigFile('/nonexistent/path.json', 'mcpServers', 'Test');

        $this->assertEmpty($servers);
    }

    public function test_parse_config_file_returns_empty_for_malformed_json(): void
    {
        $path = __DIR__.'/../../../../fixtures/mcp-configs/malformed.json';

        $servers = $this->discovery->parseConfigFile($path, 'mcpServers', 'Test');

        $this->assertEmpty($servers);
    }

    // --- scanSource ---

    public function test_scan_unknown_source_returns_empty(): void
    {
        $result = $this->discovery->scanSource('nonexistent_ide');

        $this->assertNull($result['file']);
        $this->assertEmpty($result['servers']);
    }

    // --- allSourceLabels ---

    public function test_all_source_labels_includes_six_ides(): void
    {
        $labels = $this->discovery->allSourceLabels();

        $this->assertCount(6, $labels);
        $this->assertArrayHasKey('claude_desktop', $labels);
        $this->assertArrayHasKey('claude_code', $labels);
        $this->assertArrayHasKey('cursor', $labels);
        $this->assertArrayHasKey('windsurf', $labels);
        $this->assertArrayHasKey('kiro', $labels);
        $this->assertArrayHasKey('vscode', $labels);
    }

    // --- isBridgeMode ---

    public function test_bridge_mode_false_by_default(): void
    {
        $this->assertFalse($this->discovery->isBridgeMode());
    }

    public function test_bridge_mode_true_when_docker_with_secret(): void
    {
        putenv('RUNNING_IN_DOCKER=true');
        config([
            'local_agents.bridge.auto_detect' => true,
            'local_agents.bridge.secret' => 'test-secret',
        ]);

        $discovery = new McpConfigDiscovery(new McpConfigNormalizer);

        $this->assertTrue($discovery->isBridgeMode());
    }
}
