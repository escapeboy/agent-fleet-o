<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\System\LangfuseConfigTool;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class LangfuseConfigToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $team->id);
    }

    public function test_get_returns_effective_config(): void
    {
        $data = $this->decode((new LangfuseConfigTool)->handle(new Request(['action' => 'get'])));

        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('host', $data);
        $this->assertArrayHasKey('public_key_set', $data);
        $this->assertArrayHasKey('secret_key_set', $data);
    }

    public function test_unknown_action_returns_invalid_argument_error(): void
    {
        $data = $this->decode((new LangfuseConfigTool)->handle(new Request(['action' => 'foo'])));

        $this->assertArrayHasKey('error', $data);
    }

    public function test_update_stores_values(): void
    {
        $data = $this->decode((new LangfuseConfigTool)->handle(new Request([
            'action' => 'update',
            'enabled' => true,
            'host' => 'https://langfuse.example.com',
            'public_key' => 'pk-test',
            'secret_key' => 'sk-test',
            'mask_content' => true,
        ])));

        $this->assertTrue($data['success']);
        $this->assertTrue($data['enabled']);
        $this->assertSame('https://langfuse.example.com', $data['host']);
        $this->assertTrue($data['public_key_set']);
        $this->assertTrue($data['secret_key_set']);
        $this->assertTrue($data['mask_content']);

        $stored = (array) GlobalSetting::get('langfuse_config');
        $this->assertSame('pk-test', $stored['public_key']);
        $this->assertSame('sk-test', $stored['secret_key']);
    }

    public function test_update_disables_and_keeps_existing_key_when_empty(): void
    {
        GlobalSetting::set('langfuse_config', ['enabled' => true, 'public_key' => 'pk-existing']);

        $data = $this->decode((new LangfuseConfigTool)->handle(new Request([
            'action' => 'update',
            'enabled' => false,
            'public_key' => '',
        ])));

        $this->assertTrue($data['success']);
        $this->assertFalse($data['enabled']);

        $stored = (array) GlobalSetting::get('langfuse_config');
        $this->assertFalse($stored['enabled']);
        $this->assertSame('pk-existing', $stored['public_key']);
    }

    public function test_update_rejects_non_https_host(): void
    {
        $data = $this->decode((new LangfuseConfigTool)->handle(new Request([
            'action' => 'update',
            'host' => 'http://insecure.example.com',
        ])));

        $this->assertArrayHasKey('error', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $data = json_decode((string) $response->content(), true);
        $this->assertIsArray($data);

        return $data;
    }
}
