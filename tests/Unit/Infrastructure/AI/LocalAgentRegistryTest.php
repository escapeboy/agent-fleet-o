<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\GlobalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalAgentRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function discovery(): LocalAgentDiscovery
    {
        return app(LocalAgentDiscovery::class);
    }

    public function test_custom_agent_is_merged_into_registry(): void
    {
        GlobalSetting::set('local_agents_custom', [
            'copilot' => [
                'binary' => 'copilot',
                'execute_flags' => ['-p'],
            ],
        ]);

        $agents = $this->discovery()->registeredAgents();

        $this->assertArrayHasKey('copilot', $agents);
        $this->assertSame('copilot', $agents['copilot']['binary']);
        // Built-ins remain present alongside customs.
        $this->assertArrayHasKey('claude-code', $agents);

        $cfg = $this->discovery()->agentConfig('copilot');
        $this->assertNotNull($cfg);
        $this->assertSame(['-p'], $cfg['execute_flags']);
        $this->assertTrue($cfg['custom']);
    }

    public function test_builtin_agent_wins_on_key_collision(): void
    {
        GlobalSetting::set('local_agents_custom', [
            'claude-code' => ['binary' => 'evil-shadow', 'execute_flags' => ['-x']],
        ]);

        // The shipped claude-code config must not be overridable by a custom entry.
        $this->assertSame('claude', $this->discovery()->agentConfig('claude-code')['binary']);
    }

    public function test_malformed_custom_entries_are_skipped(): void
    {
        GlobalSetting::set('local_agents_custom', [
            'no-binary' => ['execute_flags' => ['-p']],   // missing binary
            7 => ['binary' => 'x'],                        // non-string key
            'ok' => ['binary' => 'okbin', 'execute_flags' => ['-p']],
        ]);

        $custom = $this->discovery()->customAgents();

        $this->assertArrayNotHasKey('no-binary', $custom);
        $this->assertArrayHasKey('ok', $custom);
    }

    public function test_unknown_agent_config_returns_null(): void
    {
        $this->assertNull($this->discovery()->agentConfig('does-not-exist'));
    }
}
