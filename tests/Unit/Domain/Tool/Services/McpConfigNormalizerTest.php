<?php

namespace Tests\Unit\Domain\Tool\Services;

use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Services\McpConfigNormalizer;
use Tests\TestCase;

class McpConfigNormalizerTest extends TestCase
{
    private McpConfigNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new McpConfigNormalizer;
    }

    // --- classifyType ---

    public function test_classify_stdio_from_command(): void
    {
        $config = ['command' => 'npx', 'args' => ['-y', 'server']];

        $this->assertEquals(ToolType::McpStdio, $this->normalizer->classifyType($config));
    }

    public function test_classify_http_from_url(): void
    {
        $config = ['url' => 'https://mcp.example.com/sse'];

        $this->assertEquals(ToolType::McpHttp, $this->normalizer->classifyType($config));
    }

    public function test_classify_http_from_sse_type(): void
    {
        $config = ['type' => 'sse', 'url' => 'https://example.com'];

        $this->assertEquals(ToolType::McpHttp, $this->normalizer->classifyType($config));
    }

    public function test_classify_defaults_to_stdio(): void
    {
        $config = ['some_other_key' => 'value'];

        $this->assertEquals(ToolType::McpStdio, $this->normalizer->classifyType($config));
    }

    // --- extractTransportConfig ---

    public function test_extract_stdio_transport_config(): void
    {
        $config = ['command' => 'npx', 'args' => ['-y', 'server'], 'cwd' => '/tmp'];

        $result = $this->normalizer->extractTransportConfig($config, ToolType::McpStdio);

        $this->assertEquals('npx', $result['command']);
        $this->assertEquals(['-y', 'server'], $result['args']);
        $this->assertEquals('/tmp', $result['cwd']);
    }

    public function test_extract_http_transport_config_strips_auth_headers(): void
    {
        $config = [
            'url' => 'https://mcp.example.com/sse',
            'headers' => [
                'Authorization' => 'Bearer secret',
                'X-Custom-Header' => 'keep-me',
            ],
        ];

        $result = $this->normalizer->extractTransportConfig($config, ToolType::McpHttp);

        $this->assertEquals('https://mcp.example.com/sse', $result['url']);
        $this->assertArrayHasKey('headers', $result);
        $this->assertEquals(['X-Custom-Header' => 'keep-me'], $result['headers']);
    }

    public function test_extract_http_transport_config_without_auth_headers(): void
    {
        $config = [
            'url' => 'https://mcp.example.com/sse',
            'headers' => [
                'Authorization' => 'Bearer secret',
                'X-Api-Key' => 'key-123',
            ],
        ];

        $result = $this->normalizer->extractTransportConfig($config, ToolType::McpHttp);

        $this->assertArrayNotHasKey('headers', $result);
    }

    // --- extractCredentials ---

    public function test_extract_auth_headers_as_credentials(): void
    {
        $config = [
            'url' => 'https://example.com',
            'headers' => [
                'Authorization' => 'Bearer secret-token',
                'X-Auth-Token' => 'auth-token-value',
                'X-Custom' => 'not-a-credential',
            ],
        ];

        $credentials = $this->normalizer->extractCredentials($config);

        $this->assertArrayHasKey('api_key', $credentials);
        $this->assertEquals('Bearer secret-token', $credentials['api_key']);
        $this->assertArrayHasKey('auth_token', $credentials);
        $this->assertEquals('auth-token-value', $credentials['auth_token']);
        $this->assertArrayNotHasKey('x_custom', $credentials);
    }

    public function test_extract_sensitive_env_vars_as_credentials(): void
    {
        $config = [
            'command' => 'npx',
            'env' => [
                'GITHUB_TOKEN' => 'ghp_secret',
                'NODE_ENV' => 'production',
                'MY_API_KEY' => 'key123',
            ],
        ];

        $credentials = $this->normalizer->extractCredentials($config);

        $this->assertArrayHasKey('env_GITHUB_TOKEN', $credentials);
        $this->assertEquals('ghp_secret', $credentials['env_GITHUB_TOKEN']);
        $this->assertArrayHasKey('env_MY_API_KEY', $credentials);
        $this->assertArrayNotHasKey('env_NODE_ENV', $credentials);
    }

    public function test_extract_credentials_empty_when_none(): void
    {
        $config = [
            'command' => 'npx',
            'env' => ['NODE_ENV' => 'production'],
        ];

        $this->assertEmpty($this->normalizer->extractCredentials($config));
    }

    // --- generateSlug ---

    public function test_generate_slug_with_source_suffix(): void
    {
        $slug = $this->normalizer->generateSlug('my-server', 'Cursor');

        $this->assertEquals('my-server-cursor', $slug);
    }

    public function test_generate_slug_from_complex_name(): void
    {
        $slug = $this->normalizer->generateSlug('My Cool Server', 'Claude Desktop');

        $this->assertEquals('my-cool-server-claude-desktop', $slug);
    }

    // --- parseJsonInput ---

    public function test_parse_json_with_mcp_servers_key(): void
    {
        $json = json_encode([
            'mcpServers' => [
                'server-a' => ['command' => 'npx', 'args' => ['a']],
            ],
        ]);

        $result = $this->normalizer->parseJsonInput($json);

        $this->assertArrayHasKey('server-a', $result);
        $this->assertEquals('npx', $result['server-a']['command']);
    }

    public function test_parse_json_with_servers_key(): void
    {
        $json = json_encode([
            'servers' => [
                'server-b' => ['command' => 'node', 'args' => ['b']],
            ],
        ]);

        $result = $this->normalizer->parseJsonInput($json);

        $this->assertArrayHasKey('server-b', $result);
    }

    public function test_parse_json_direct_servers_object(): void
    {
        $json = json_encode([
            'server-c' => ['command' => 'python3', 'args' => ['c.py']],
        ]);

        $result = $this->normalizer->parseJsonInput($json);

        $this->assertArrayHasKey('server-c', $result);
    }

    public function test_parse_invalid_json_returns_empty(): void
    {
        $this->assertEmpty($this->normalizer->parseJsonInput('not json'));
    }

    public function test_parse_json_unrecognized_structure_returns_empty(): void
    {
        $json = json_encode(['foo' => 'bar', 'baz' => 42]);

        $this->assertEmpty($this->normalizer->parseJsonInput($json));
    }

    // --- isUrlSafe ---

    public function test_url_safe_rejects_non_http_schemes(): void
    {
        $this->assertFalse($this->normalizer->isUrlSafe('ftp://example.com'));
        $this->assertFalse($this->normalizer->isUrlSafe('file:///etc/passwd'));
    }

    public function test_url_safe_rejects_localhost(): void
    {
        $this->assertFalse($this->normalizer->isUrlSafe('http://localhost:8080'));
        $this->assertFalse($this->normalizer->isUrlSafe('http://0.0.0.0:3000'));
    }

    public function test_url_safe_rejects_invalid_urls(): void
    {
        $this->assertFalse($this->normalizer->isUrlSafe('not-a-url'));
        $this->assertFalse($this->normalizer->isUrlSafe(''));
    }

    public function test_url_safe_accepts_public_https(): void
    {
        $this->assertTrue($this->normalizer->isUrlSafe('https://mcp.example.com/sse'));
    }

    // --- isCommandSafe ---

    public function test_command_safe_allows_npx(): void
    {
        $this->assertTrue($this->normalizer->isCommandSafe('npx'));
    }

    public function test_command_safe_allows_python3(): void
    {
        $this->assertTrue($this->normalizer->isCommandSafe('python3'));
    }

    public function test_command_safe_allows_docker(): void
    {
        $this->assertTrue($this->normalizer->isCommandSafe('docker'));
    }

    public function test_command_safe_rejects_unknown_binary(): void
    {
        $this->assertFalse($this->normalizer->isCommandSafe('rm'));
        $this->assertFalse($this->normalizer->isCommandSafe('curl'));
        $this->assertFalse($this->normalizer->isCommandSafe('/usr/local/bin/custom-script'));
    }

    public function test_command_safe_extracts_basename(): void
    {
        $this->assertTrue($this->normalizer->isCommandSafe('/usr/local/bin/npx'));
    }

    // --- normalize (integration) ---

    public function test_normalize_stdio_server(): void
    {
        $raw = [
            'command' => 'npx',
            'args' => ['-y', '@modelcontextprotocol/server-filesystem'],
            'env' => ['GITHUB_TOKEN' => 'secret', 'NODE_ENV' => 'prod'],
        ];

        $result = $this->normalizer->normalize('filesystem', $raw, 'Claude Desktop');

        $this->assertEquals('Filesystem', $result['name']);
        $this->assertEquals('filesystem-claude-desktop', $result['slug']);
        $this->assertEquals('Claude Desktop', $result['source']);
        $this->assertEquals('mcp_stdio', $result['type']);
        $this->assertEquals('npx', $result['transport_config']['command']);
        $this->assertArrayHasKey('env_GITHUB_TOKEN', $result['credentials']);
        $this->assertFalse($result['disabled']);
        $this->assertEmpty($result['warnings']);
    }

    public function test_normalize_http_server_with_credentials(): void
    {
        $raw = [
            'url' => 'https://mcp.example.com/sse',
            'headers' => [
                'Authorization' => 'Bearer token-123',
                'X-Custom' => 'safe',
            ],
        ];

        $result = $this->normalizer->normalize('remote-server', $raw, 'Cursor');

        $this->assertEquals('Remote Server', $result['name']);
        $this->assertEquals('mcp_http', $result['type']);
        $this->assertEquals('https://mcp.example.com/sse', $result['transport_config']['url']);
        $this->assertEquals(['X-Custom' => 'safe'], $result['transport_config']['headers']);
        $this->assertArrayHasKey('api_key', $result['credentials']);
        $this->assertEquals('Bearer token-123', $result['credentials']['api_key']);
    }

    public function test_normalize_disabled_server(): void
    {
        $raw = ['command' => 'npx', 'args' => [], 'disabled' => true];

        $result = $this->normalizer->normalize('disabled-tool', $raw, 'Cursor');

        $this->assertTrue($result['disabled']);
    }

    public function test_normalize_unsafe_command_generates_warning(): void
    {
        $raw = ['command' => 'custom-binary', 'args' => []];

        $result = $this->normalizer->normalize('risky-tool', $raw, 'Cursor');

        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('not in the safe allowlist', $result['warnings'][0]);
    }
}
