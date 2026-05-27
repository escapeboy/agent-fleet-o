<?php

namespace Tests\Feature\Mcp;

use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Mcp\Tools\Shared\LocalAgentCustomManageTool;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class LocalAgentCustomManageToolTest extends TestCase
{
    use RefreshDatabase;

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    private function asSuperAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    }

    public function test_non_super_admin_is_denied(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => false]));

        $response = (new LocalAgentCustomManageTool)->handle(new Request(['action' => 'list']));

        $this->assertTrue($response->isError());
    }

    public function test_super_admin_registers_a_custom_agent_and_it_becomes_resolvable(): void
    {
        $this->asSuperAdmin();

        $response = (new LocalAgentCustomManageTool)->handle(new Request([
            'action' => 'register',
            'key' => 'acme-cli',
            'name' => 'Acme CLI',
            'binary' => 'acme',
            'execute_flags' => ['-p', '--allow-all-tools'],
            'capabilities' => ['code_generation'],
        ]));

        $this->assertFalse($response->isError(), (string) $response->content());
        $this->assertSame('acme-cli', $this->decode($response)['registered']);

        // Persisted and visible to the discovery registry / gateway resolution.
        $this->assertArrayHasKey('acme-cli', GlobalSetting::get('local_agents_custom', []));
        $this->assertNotNull(app(LocalAgentDiscovery::class)->agentConfig('acme-cli'));
    }

    public function test_register_rejects_bad_key_binary_and_missing_flags(): void
    {
        $this->asSuperAdmin();
        $tool = new LocalAgentCustomManageTool;

        $badKey = $tool->handle(new Request(['action' => 'register', 'key' => 'Bad Key!', 'binary' => 'x', 'execute_flags' => ['-p']]));
        $this->assertTrue($badKey->isError());

        $badBinary = $tool->handle(new Request(['action' => 'register', 'key' => 'ok', 'binary' => 'rm -rf /; evil', 'execute_flags' => ['-p']]));
        $this->assertTrue($badBinary->isError());

        $noFlags = $tool->handle(new Request(['action' => 'register', 'key' => 'ok', 'binary' => 'okbin', 'execute_flags' => []]));
        $this->assertTrue($noFlags->isError());
    }

    public function test_cannot_shadow_a_builtin_agent(): void
    {
        $this->asSuperAdmin();

        $response = (new LocalAgentCustomManageTool)->handle(new Request([
            'action' => 'register',
            'key' => 'claude-code',
            'binary' => 'evil',
            'execute_flags' => ['-p'],
        ]));

        $this->assertTrue($response->isError());
    }

    public function test_remove_deletes_a_custom_agent(): void
    {
        $this->asSuperAdmin();
        GlobalSetting::set('local_agents_custom', ['copilot' => ['binary' => 'copilot', 'execute_flags' => ['-p']]]);

        $response = (new LocalAgentCustomManageTool)->handle(new Request(['action' => 'remove', 'key' => 'copilot']));

        $this->assertFalse($response->isError(), (string) $response->content());
        $this->assertArrayNotHasKey('copilot', GlobalSetting::get('local_agents_custom', []));
    }
}
