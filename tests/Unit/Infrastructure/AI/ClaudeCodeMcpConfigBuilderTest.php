<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Services\ClaudeCodeMcpConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ClaudeCodeMcpConfigBuilderTest extends TestCase
{
    use RefreshDatabase;

    private ClaudeCodeMcpConfigBuilder $builder;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new ClaudeCodeMcpConfigBuilder;
        $this->team = Team::factory()->create();
    }

    public function test_empty_collection_returns_empty_array(): void
    {
        $this->assertSame([], $this->builder->build(new Collection));
    }

    public function test_mcp_http_with_full_transport_config_is_translated(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'fleetq_platform',
            'type' => ToolType::McpHttp,
            'transport_config' => [
                'url' => 'http://nginx',
                'headers' => [
                    'Host' => 'fleetq.net',
                    'Authorization' => 'Bearer token-123',
                ],
            ],
            'credentials' => [],
        ]);

        $config = $this->builder->build(collect([$tool]));

        $this->assertArrayHasKey('mcpServers', $config);
        $this->assertArrayHasKey('fleetq_platform', $config['mcpServers']);
        $this->assertSame([
            'type' => 'http',
            'url' => 'http://nginx',
            'headers' => [
                'Host' => 'fleetq.net',
                'Authorization' => 'Bearer token-123',
            ],
        ], $config['mcpServers']['fleetq_platform']);
    }

    public function test_mcp_http_with_inline_credentials_adds_authorization_header(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'github_mcp',
            'type' => ToolType::McpHttp,
            'transport_config' => [
                'url' => 'https://api.github.com/mcp',
                'headers' => ['User-Agent' => 'fleetq'],
            ],
            'credentials' => ['api_key' => 'ghp_secret'],
        ]);

        $config = $this->builder->build(collect([$tool]));
        $entry = $config['mcpServers']['github_mcp'];

        $this->assertSame('Bearer ghp_secret', $entry['headers']['Authorization']);
        $this->assertSame('fleetq', $entry['headers']['User-Agent']);
    }

    public function test_mcp_http_preserves_existing_authorization_header_over_credentials(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'preset',
            'type' => ToolType::McpHttp,
            'transport_config' => [
                'url' => 'https://example.com',
                'headers' => ['Authorization' => 'Bearer prebaked'],
            ],
            'credentials' => ['api_key' => 'should-be-ignored'],
        ]);

        $entry = $this->builder->build(collect([$tool]))['mcpServers']['preset'];
        $this->assertSame('Bearer prebaked', $entry['headers']['Authorization']);
    }

    public function test_mcp_stdio_with_command_args_env_is_translated(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'atlassian_mcp',
            'type' => ToolType::McpStdio,
            'transport_config' => [
                'command' => 'npx',
                'args' => ['-y', '@anthropic-ai/mcp-server-atlassian'],
                'env' => ['ATLASSIAN_API_TOKEN' => 'tok-1'],
            ],
            'credentials' => [],
        ]);

        $entry = $this->builder->build(collect([$tool]))['mcpServers']['atlassian_mcp'];
        $this->assertSame('npx', $entry['command']);
        $this->assertSame(['-y', '@anthropic-ai/mcp-server-atlassian'], $entry['args']);
        $this->assertSame(['ATLASSIAN_API_TOKEN' => 'tok-1'], $entry['env']);
        $this->assertArrayNotHasKey('type', $entry); // stdio has no type field
    }

    public function test_mcp_stdio_resolves_empty_env_from_credentials_case_insensitive(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'hubspot_mcp',
            'type' => ToolType::McpStdio,
            'transport_config' => [
                'command' => 'npx',
                'args' => ['-y', '@hubspot/mcp-server'],
                'env' => ['HUBSPOT_ACCESS_TOKEN' => ''],
            ],
            'credentials' => ['hubspot_access_token' => 'hub-secret'],
        ]);

        $entry = $this->builder->build(collect([$tool]))['mcpServers']['hubspot_mcp'];
        $this->assertSame('hub-secret', $entry['env']['HUBSPOT_ACCESS_TOKEN']);
    }

    public function test_mcp_stdio_strips_empty_env_when_no_matching_credential(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'orphan',
            'type' => ToolType::McpStdio,
            'transport_config' => [
                'command' => 'echo',
                'env' => ['SOME_KEY' => ''],
            ],
            'credentials' => [],
        ]);

        $entry = $this->builder->build(collect([$tool]))['mcpServers']['orphan'];
        $this->assertArrayNotHasKey('env', $entry);
    }

    public function test_built_in_tool_is_omitted(): void
    {
        $tool = Tool::factory()->builtIn()->create([
            'team_id' => $this->team->id,
            'slug' => 'bash',
        ]);

        $this->assertSame([], $this->builder->build(collect([$tool])));
    }

    public function test_mcp_http_with_missing_url_is_skipped(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'broken',
            'type' => ToolType::McpHttp,
            'transport_config' => ['headers' => ['X' => 'y']],
        ]);

        $this->assertSame([], $this->builder->build(collect([$tool])));
    }

    public function test_mcp_stdio_with_missing_command_is_skipped(): void
    {
        $tool = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'no-cmd',
            'type' => ToolType::McpStdio,
            'transport_config' => ['args' => ['x']],
        ]);

        $this->assertSame([], $this->builder->build(collect([$tool])));
    }

    public function test_mixed_collection_keeps_only_translatable_tools(): void
    {
        $http = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'http_tool',
            'type' => ToolType::McpHttp,
            'transport_config' => ['url' => 'https://x'],
            'credentials' => [],
        ]);
        $stdio = Tool::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'stdio_tool',
            'type' => ToolType::McpStdio,
            'transport_config' => ['command' => 'echo'],
            'credentials' => [],
        ]);
        $builtIn = Tool::factory()->builtIn()->create([
            'team_id' => $this->team->id,
            'slug' => 'bash',
        ]);

        $config = $this->builder->build(collect([$http, $stdio, $builtIn]));

        $this->assertCount(2, $config['mcpServers']);
        $this->assertArrayHasKey('http_tool', $config['mcpServers']);
        $this->assertArrayHasKey('stdio_tool', $config['mcpServers']);
        $this->assertArrayNotHasKey('bash', $config['mcpServers']);
    }
}
