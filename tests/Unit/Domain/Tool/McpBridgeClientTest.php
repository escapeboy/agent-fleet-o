<?php

namespace Tests\Unit\Domain\Tool;

use App\Domain\Bridge\Enums\BridgeConnectionStatus;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Bridge\Services\BridgeRouter;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpBridgeClient;
use App\Infrastructure\Bridge\BridgeRequestRegistry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class McpBridgeClientTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private BridgeConnection $bridge;

    private Tool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'plan' => 'pro',
            'settings' => [],
        ]);

        $this->bridge = BridgeConnection::create([
            'team_id' => $this->team->id,
            'session_id' => 'relay-test-123',
            'status' => BridgeConnectionStatus::Connected,
            'bridge_version' => '1.0',
            'endpoints' => [
                'agents' => [],
                'mcp_servers' => [
                    ['name' => 'playwright'],
                    ['name' => 'filesystem'],
                ],
            ],
            'connected_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->tool = Tool::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'name' => 'Playwright',
            'slug' => 'playwright',
            'type' => ToolType::McpBridge,
            'status' => ToolStatus::Active,
            'transport_config' => [
                'bridge_server_name' => 'playwright',
            ],
            'tool_definitions' => [
                [
                    'name' => 'browser_navigate',
                    'description' => 'Navigate to a URL',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'URL to navigate to'],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Build a McpBridgeClient with a mocked registry.
     */
    private function buildClient(?BridgeRequestRegistry $registry = null): McpBridgeClient
    {
        return new McpBridgeClient(
            app(BridgeRouter::class),
            $registry ?? $this->mockRegistry(),
        );
    }

    /**
     * Create a mock registry that returns the given chunk.
     */
    private function mockRegistry(string $chunk = 'ok', ?array $usage = null): BridgeRequestRegistry
    {
        $mock = $this->createMock(BridgeRequestRegistry::class);
        $mock->method('register');
        $mock->method('popChunk')->willReturn([
            'chunk' => $chunk,
            'done' => true,
            'usage' => null,
        ]);
        $mock->method('getUsage')->willReturn($usage);

        return $mock;
    }

    /**
     * Fake Redis so RPUSH calls don't hit a real server.
     */
    private function fakeRedis(): void
    {
        Redis::shouldReceive('connection')->with('bridge')->andReturnSelf();
        Redis::shouldReceive('rpush')->andReturn(1);
    }

    public function test_call_tool_returns_chunk_from_registry(): void
    {
        $this->fakeRedis();

        $client = $this->buildClient($this->mockRegistry('Navigated to https://example.com'));
        $result = $client->callTool($this->tool, 'browser_navigate', ['url' => 'https://example.com']);

        $this->assertEquals('Navigated to https://example.com', $result);
    }

    public function test_call_tool_throws_when_no_bridge_connected(): void
    {
        $this->bridge->update([
            'status' => BridgeConnectionStatus::Disconnected,
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $client = $this->buildClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no active bridge');

        $client->callTool($this->tool, 'browser_navigate', ['url' => 'https://example.com']);
    }

    public function test_call_tool_throws_on_timeout(): void
    {
        $this->fakeRedis();

        $registry = $this->createMock(BridgeRequestRegistry::class);
        $registry->method('register');
        $registry->method('popChunk')->willReturn(null);

        $client = $this->buildClient($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('timeout');

        $client->callTool($this->tool, 'browser_navigate', ['url' => 'https://example.com']);
    }

    public function test_call_tool_throws_on_error_sentinel(): void
    {
        $this->fakeRedis();

        $registry = $this->createMock(BridgeRequestRegistry::class);
        $registry->method('register');
        $registry->method('popChunk')->willReturn(['chunk' => '', 'done' => true, 'usage' => null]);
        $registry->method('getUsage')->willReturn(['__error' => 'Tool not found']);

        $client = $this->buildClient($registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tool not found');

        $client->callTool($this->tool, 'nonexistent_tool', []);
    }

    public function test_call_tool_sends_correct_server_name_and_frame_type(): void
    {
        Redis::shouldReceive('connection')->with('bridge')->andReturnSelf();
        Redis::shouldReceive('rpush')->once()->withArgs(function ($key, $payload) {
            $data = json_decode($payload, true);

            return $key === "bridge:req:{$this->team->id}"
                && $data['frame_type'] === 0x0020
                && $data['payload']['server'] === 'playwright'
                && $data['payload']['method'] === 'tools/call'
                && $data['payload']['params']['name'] === 'browser_navigate';
        })->andReturn(1);

        $client = $this->buildClient();
        $client->callTool($this->tool, 'browser_navigate', ['url' => 'https://example.com']);
    }

    public function test_discover_tools_from_bridge(): void
    {
        $this->fakeRedis();

        $toolsJson = json_encode([
            'tools' => [
                ['name' => 'browser_navigate', 'description' => 'Navigate'],
                ['name' => 'browser_click', 'description' => 'Click'],
            ],
        ]);

        $client = $this->buildClient($this->mockRegistry($toolsJson));
        $tools = $client->discover($this->tool);

        $this->assertCount(2, $tools);
        $this->assertEquals('browser_navigate', $tools[0]['name']);
    }

    public function test_call_tool_returns_no_output_on_empty_done_chunk(): void
    {
        $this->fakeRedis();

        $client = $this->buildClient($this->mockRegistry(''));
        $result = $client->callTool($this->tool, 'browser_navigate', ['url' => 'https://example.com']);

        $this->assertEquals('(no output)', $result);
    }
}
