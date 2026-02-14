<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Tool\Models\Tool;

class ToolControllerTest extends ApiTestCase
{
    private function createTool(array $overrides = []): Tool
    {
        return Tool::create(array_merge([
            'team_id' => $this->team->id,
            'name' => 'Test Tool',
            'slug' => 'test-tool',
            'type' => 'mcp_stdio',
            'status' => 'active',
            'transport_config' => ['command' => 'npx', 'args' => ['-y', 'test-server']],
            'credentials' => [],
            'tool_definitions' => [],
            'settings' => [],
        ], $overrides));
    }

    public function test_can_list_tools(): void
    {
        $this->actingAsApiUser();
        $this->createTool(['name' => 'Tool One', 'slug' => 'tool-one']);
        $this->createTool(['name' => 'Tool Two', 'slug' => 'tool-two']);

        $response = $this->getJson('/api/v1/tools');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'type']],
            ]);
    }

    public function test_can_filter_tools_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createTool(['name' => 'Active', 'slug' => 'active', 'status' => 'active']);
        $this->createTool(['name' => 'Disabled', 'slug' => 'disabled', 'status' => 'disabled']);

        $response = $this->getJson('/api/v1/tools?filter[status]=active');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active');
    }

    public function test_can_show_tool(): void
    {
        $this->actingAsApiUser();
        $tool = $this->createTool();

        $response = $this->getJson("/api/v1/tools/{$tool->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $tool->id)
            ->assertJsonPath('data.name', 'Test Tool');
    }

    public function test_can_create_tool(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/tools', [
            'name' => 'New MCP Tool',
            'type' => 'mcp_stdio',
            'description' => 'A test tool',
            'transport_config' => ['command' => 'node', 'args' => ['server.js']],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New MCP Tool')
            ->assertJsonPath('data.type', 'mcp_stdio');

        $this->assertDatabaseHas('tools', ['name' => 'New MCP Tool']);
    }

    public function test_create_tool_requires_name(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/tools', [
            'type' => 'mcp_stdio',
            'transport_config' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_update_tool(): void
    {
        $this->actingAsApiUser();
        $tool = $this->createTool();

        $response = $this->putJson("/api/v1/tools/{$tool->id}", [
            'name' => 'Updated Tool',
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Tool');
    }

    public function test_can_delete_tool(): void
    {
        $this->actingAsApiUser();
        $tool = $this->createTool();

        $response = $this->deleteJson("/api/v1/tools/{$tool->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Tool deleted.']);

        $this->assertSoftDeleted('tools', ['id' => $tool->id]);
    }

    public function test_unauthenticated_cannot_list_tools(): void
    {
        $response = $this->getJson('/api/v1/tools');

        $response->assertStatus(401);
    }
}
