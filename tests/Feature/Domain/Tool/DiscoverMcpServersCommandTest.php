<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpConfigDiscovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DiscoverMcpServersCommandTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $user->update(['current_team_id' => $this->team->id]);
    }

    private function mockDiscovery(array $servers = [], array $sources = []): void
    {
        $mock = Mockery::mock(McpConfigDiscovery::class);

        $mock->shouldReceive('scanAllSources')->andReturn([
            'sources' => $sources,
            'servers' => $servers,
        ]);

        $mock->shouldReceive('scanSource')->andReturn([
            'file' => '/tmp/test.json',
            'servers' => $servers,
        ]);

        $mock->shouldReceive('allSourceLabels')->andReturn([
            'claude_desktop' => 'Claude Desktop',
            'claude_code' => 'Claude Code',
            'cursor' => 'Cursor',
            'windsurf' => 'Windsurf',
            'kiro' => 'Kiro',
            'vscode' => 'VS Code',
        ]);

        $mock->shouldReceive('isBridgeMode')->andReturn(false);

        $this->app->instance(McpConfigDiscovery::class, $mock);
    }

    /**
     * Use mcp_http type — works in both community and cloud editions.
     */
    private function makeServer(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Server',
            'slug' => 'test-server-cursor',
            'source' => 'Cursor',
            'type' => 'mcp_http',
            'transport_config' => ['url' => 'https://mcp.example.com/sse'],
            'credentials' => [],
            'disabled' => false,
            'warnings' => [],
        ], $overrides);
    }

    public function test_reports_no_servers_found(): void
    {
        $this->mockDiscovery();

        $this->artisan('tools:discover')
            ->expectsOutputToContain('No MCP servers found')
            ->assertSuccessful();
    }

    public function test_lists_discovered_servers(): void
    {
        $this->mockDiscovery(
            servers: [
                $this->makeServer(['name' => 'Remote MCP', 'slug' => 'remote-mcp-cursor']),
            ],
            sources: ['cursor' => ['label' => 'Cursor', 'file' => '~/.cursor/mcp.json', 'count' => 1]],
        );

        $this->artisan('tools:discover')
            ->expectsOutputToContain('Found 1 server(s)')
            ->assertSuccessful();
    }

    public function test_rejects_invalid_source(): void
    {
        $this->mockDiscovery();

        $this->artisan('tools:discover --source=invalid_ide')
            ->expectsOutputToContain('Unknown source')
            ->assertFailed();
    }

    public function test_dry_run_does_not_import(): void
    {
        $this->mockDiscovery(
            servers: [$this->makeServer()],
            sources: ['cursor' => ['label' => 'Cursor', 'file' => '', 'count' => 1]],
        );

        $this->artisan('tools:discover --dry-run --import')
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain('1 server(s) would be imported')
            ->assertSuccessful();

        $this->assertEquals(0, Tool::withoutGlobalScopes()->count());
    }

    public function test_import_creates_tools(): void
    {
        $this->mockDiscovery(
            servers: [$this->makeServer()],
            sources: ['cursor' => ['label' => 'Cursor', 'file' => '', 'count' => 1]],
        );

        $this->artisan('tools:discover --import --no-interaction')
            ->assertSuccessful();

        $this->assertEquals(1, Tool::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }

    public function test_import_fails_without_team(): void
    {
        Team::query()->delete();

        $this->mockDiscovery(
            servers: [$this->makeServer()],
            sources: ['cursor' => ['label' => 'Cursor', 'file' => '', 'count' => 1]],
        );

        $this->artisan('tools:discover --import --no-interaction')
            ->expectsOutputToContain('No team found')
            ->assertFailed();
    }

    public function test_shows_warnings_for_servers(): void
    {
        $this->mockDiscovery(
            servers: [
                $this->makeServer([
                    'name' => 'Risky Server',
                    'warnings' => ['URL points to a private address'],
                ]),
            ],
            sources: ['cursor' => ['label' => 'Cursor', 'file' => '', 'count' => 1]],
        );

        $this->artisan('tools:discover')
            ->expectsOutputToContain('URL points to a private address')
            ->assertSuccessful();
    }
}
