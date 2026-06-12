<?php

namespace Tests\Feature\Mcp;

use App\Domain\Tool\Actions\CreateMcpRegistryEntryAction;
use App\Domain\Tool\Models\McpServerRegistry;
use App\Mcp\Tools\Tool\McpRegistryCreateTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class McpRegistryCreateToolTest extends TestCase
{
    use RefreshDatabase;

    private function callTool(): Response
    {
        return (new McpRegistryCreateTool)->handle(new Request([
            'name' => 'Slack MCP',
            'transport' => 'mcp_http',
            'connection' => ['url' => 'https://example.com/mcp'],
        ]), new CreateMcpRegistryEntryAction);
    }

    public function test_non_super_admin_is_denied_in_cloud_mode(): void
    {
        config(['app.deployment_mode' => 'cloud']);
        $this->actingAs(User::factory()->create(['is_super_admin' => false]));

        $response = $this->callTool();

        $this->assertTrue($response->isError());
        $this->assertSame(0, McpServerRegistry::query()->count());
    }

    public function test_super_admin_can_create_entry_in_cloud_mode(): void
    {
        config(['app.deployment_mode' => 'cloud']);
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));

        $response = $this->callTool();

        $this->assertFalse($response->isError(), (string) $response->content());
        $this->assertSame('slack-mcp', json_decode((string) $response->content(), true)['slug']);
        $this->assertSame(1, McpServerRegistry::query()->count());
    }

    public function test_authenticated_user_can_create_entry_in_self_hosted_mode(): void
    {
        config(['app.deployment_mode' => 'self_hosted', 'cloud.mode' => false]);
        $this->actingAs(User::factory()->create(['is_super_admin' => false]));

        $response = $this->callTool();

        $this->assertFalse($response->isError(), (string) $response->content());
        $this->assertSame(1, McpServerRegistry::query()->count());
    }
}
