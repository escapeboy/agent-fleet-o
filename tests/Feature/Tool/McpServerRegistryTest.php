<?php

namespace Tests\Feature\Tool;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\CreateMcpRegistryEntryAction;
use App\Domain\Tool\Actions\InstallFromRegistryAction;
use App\Domain\Tool\Enums\RegistryTrustLevel;
use App\Domain\Tool\Models\McpServerRegistry;
use App\Domain\Tool\Models\Tool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class McpServerRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Registry Test Team',
            'slug' => 'registry-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
    }

    public function test_create_entry_assigns_unique_slug(): void
    {
        $action = new CreateMcpRegistryEntryAction;

        $first = $action->execute([
            'name' => 'Slack MCP',
            'transport' => 'mcp_http',
            'connection' => ['url' => 'https://example.com/mcp'],
        ], $this->user->id);

        $second = $action->execute([
            'name' => 'Slack MCP',
            'transport' => 'mcp_http',
            'connection' => ['url' => 'https://other.example.com/mcp'],
        ], $this->user->id);

        $this->assertSame('slack-mcp', $first->slug);
        $this->assertSame('slack-mcp-2', $second->slug);
        $this->assertSame($this->user->id, $first->created_by);
        $this->assertSame(RegistryTrustLevel::Community, $first->trust_level);
        $this->assertTrue($first->is_active);
    }

    public function test_install_creates_tool_linked_to_registry_entry(): void
    {
        $entry = (new CreateMcpRegistryEntryAction)->execute([
            'name' => 'GitHub MCP',
            'description' => 'GitHub Issues & PR ops',
            'transport' => 'mcp_stdio',
            'connection' => ['command' => 'npx', 'args' => ['-y', '@modelcontextprotocol/server-github']],
            'trust_level' => 'platform_trusted',
        ], $this->user->id);

        $tool = (new InstallFromRegistryAction)->execute($entry, $this->team->id);

        $this->assertNotNull($tool->id);
        $this->assertSame($this->team->id, $tool->team_id);
        $this->assertSame($entry->id, $tool->registry_server_id);
        $this->assertSame('registry-github-mcp', $tool->slug);
        $this->assertSame('mcp_stdio', $tool->type->value);
        $this->assertTrue((bool) ($tool->settings['installed_from_registry'] ?? false));
        $this->assertSame('platform_trusted', $tool->settings['registry_trust_level'] ?? null);
    }

    public function test_install_is_idempotent_per_team(): void
    {
        $entry = (new CreateMcpRegistryEntryAction)->execute([
            'name' => 'Notion',
            'transport' => 'mcp_http',
            'connection' => ['url' => 'https://api.notion.example/mcp'],
        ]);

        $action = new InstallFromRegistryAction;
        $first = $action->execute($entry, $this->team->id);
        $second = $action->execute($entry, $this->team->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            Tool::query()->where('team_id', $this->team->id)->where('registry_server_id', $entry->id)->count(),
        );
    }

    public function test_install_rejects_inactive_entries(): void
    {
        $entry = (new CreateMcpRegistryEntryAction)->execute([
            'name' => 'Deprecated server',
            'transport' => 'mcp_stdio',
            'connection' => ['command' => 'noop'],
        ]);
        $entry->update(['is_active' => false]);

        $this->expectException(RuntimeException::class);
        (new InstallFromRegistryAction)->execute($entry, $this->team->id);
    }

    public function test_registry_entry_deletion_leaves_installed_tool_intact(): void
    {
        $entry = (new CreateMcpRegistryEntryAction)->execute([
            'name' => 'Sunset MCP',
            'transport' => 'mcp_stdio',
            'connection' => ['command' => 'noop'],
        ]);

        $tool = (new InstallFromRegistryAction)->execute($entry, $this->team->id);

        $entry->delete();
        $tool->refresh();

        $this->assertNotNull($tool->id);
        $this->assertNull($tool->registry_server_id, 'FK should be nulled, tool itself preserved');
        $this->assertSame(0, McpServerRegistry::query()->count());
    }
}
