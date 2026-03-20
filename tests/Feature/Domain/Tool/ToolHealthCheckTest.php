<?php

namespace Tests\Feature\Domain\Tool;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\McpHttpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ToolHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check_marks_healthy_tool(): void
    {
        $tool = Tool::factory()->create([
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://example.com/mcp'],
            'tool_definitions' => [['name' => 'test', 'description' => 'A test tool']],
        ]);

        $mock = Mockery::mock(McpHttpClient::class);
        $mock->shouldReceive('listTools')
            ->once()
            ->andReturn([['name' => 'test', 'description' => 'A test tool']]);

        $this->app->instance(McpHttpClient::class, $mock);

        $this->artisan('tools:health-check')
            ->assertSuccessful();

        $tool->refresh();
        $this->assertSame('healthy', $tool->health_status);
        $this->assertNotNull($tool->last_health_check);
    }

    public function test_health_check_marks_unreachable_tool(): void
    {
        $tool = Tool::factory()->create([
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://example.com/mcp'],
        ]);

        $mock = Mockery::mock(McpHttpClient::class);
        $mock->shouldReceive('listTools')
            ->once()
            ->andThrow(new \RuntimeException('Connection refused'));

        $this->app->instance(McpHttpClient::class, $mock);

        $this->artisan('tools:health-check')
            ->assertSuccessful();

        $tool->refresh();
        $this->assertSame('unreachable', $tool->health_status);
    }

    public function test_health_check_refreshes_changed_definitions(): void
    {
        $tool = Tool::factory()->create([
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Active,
            'transport_config' => ['url' => 'https://example.com/mcp'],
            'tool_definitions' => [['name' => 'old_tool', 'description' => 'Old']],
        ]);

        $newDefs = [
            ['name' => 'old_tool', 'description' => 'Old'],
            ['name' => 'new_tool', 'description' => 'New tool added'],
        ];

        $mock = Mockery::mock(McpHttpClient::class);
        $mock->shouldReceive('listTools')
            ->once()
            ->andReturn($newDefs);

        $this->app->instance(McpHttpClient::class, $mock);

        $this->artisan('tools:health-check')
            ->assertSuccessful();

        $tool->refresh();
        $this->assertCount(2, $tool->tool_definitions);
    }

    public function test_health_check_skips_stdio_tools(): void
    {
        Tool::factory()->create([
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
        ]);

        $mock = Mockery::mock(McpHttpClient::class);
        $mock->shouldNotReceive('listTools');

        $this->app->instance(McpHttpClient::class, $mock);

        $this->artisan('tools:health-check')
            ->assertSuccessful();
    }

    public function test_health_check_skips_disabled_tools(): void
    {
        Tool::factory()->create([
            'type' => ToolType::McpHttp,
            'status' => ToolStatus::Disabled,
        ]);

        $mock = Mockery::mock(McpHttpClient::class);
        $mock->shouldNotReceive('listTools');

        $this->app->instance(McpHttpClient::class, $mock);

        $this->artisan('tools:health-check')
            ->assertSuccessful();
    }

    public function test_server_capabilities_column_exists(): void
    {
        $tool = Tool::factory()->create([
            'server_capabilities' => [
                'tools' => ['listChanged' => true],
                'protocol_version' => '2025-06-18',
            ],
        ]);

        $tool->refresh();
        $this->assertIsArray($tool->server_capabilities);
        $this->assertTrue($tool->server_capabilities['tools']['listChanged']);
    }
}
