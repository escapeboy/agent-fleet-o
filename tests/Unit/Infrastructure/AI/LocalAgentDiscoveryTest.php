<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use Tests\TestCase;

class LocalAgentDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests cover native (non-bridge) detection
        putenv('RUNNING_IN_DOCKER=false');
        config(['local_agents.bridge.secret' => '']);
    }

    protected function tearDown(): void
    {
        putenv('RUNNING_IN_DOCKER');
        parent::tearDown();
    }

    public function test_detect_returns_empty_when_disabled(): void
    {
        config(['local_agents.enabled' => false]);

        $discovery = new LocalAgentDiscovery;

        $this->assertEmpty($discovery->detect());
    }

    public function test_is_available_returns_false_when_disabled(): void
    {
        config(['local_agents.enabled' => false]);

        $discovery = new LocalAgentDiscovery;

        $this->assertFalse($discovery->isAvailable('codex'));
        $this->assertFalse($discovery->isAvailable('claude-code'));
    }

    public function test_is_available_returns_false_for_unknown_agent(): void
    {
        config(['local_agents.enabled' => true]);

        $discovery = new LocalAgentDiscovery;

        $this->assertFalse($discovery->isAvailable('nonexistent-agent'));
    }

    public function test_binary_path_returns_null_for_unknown_agent(): void
    {
        $discovery = new LocalAgentDiscovery;

        $this->assertNull($discovery->binaryPath('nonexistent-agent'));
    }

    public function test_version_returns_null_for_unknown_agent(): void
    {
        $discovery = new LocalAgentDiscovery;

        $this->assertNull($discovery->version('nonexistent-agent'));
    }

    public function test_all_agents_returns_config(): void
    {
        config(['local_agents.agents' => [
            'codex' => ['name' => 'OpenAI Codex', 'binary' => 'codex'],
            'claude-code' => ['name' => 'Claude Code', 'binary' => 'claude'],
        ]]);

        $discovery = new LocalAgentDiscovery;

        $agents = $discovery->allAgents();
        $this->assertArrayHasKey('codex', $agents);
        $this->assertArrayHasKey('claude-code', $agents);
        $this->assertEquals('OpenAI Codex', $agents['codex']['name']);
    }

    public function test_detect_finds_available_binaries(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment');
        }

        config(['local_agents.enabled' => true]);

        // Use 'php' as a binary that's guaranteed to exist
        config(['local_agents.agents' => [
            'test-agent' => [
                'name' => 'Test Agent',
                'binary' => 'php',
                'description' => 'Test agent using PHP binary',
                'detect_command' => 'php --version',
                'requires_env' => '',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = new LocalAgentDiscovery;
        $detected = $discovery->detect();

        $this->assertArrayHasKey('test-agent', $detected);
        $this->assertEquals('Test Agent', $detected['test-agent']['name']);
        $this->assertNotEmpty($detected['test-agent']['version']);
        $this->assertNotEmpty($detected['test-agent']['path']);
    }

    public function test_detect_skips_unavailable_binaries(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment');
        }

        config(['local_agents.enabled' => true]);

        config(['local_agents.agents' => [
            'missing-agent' => [
                'name' => 'Missing Agent',
                'binary' => 'this-binary-definitely-does-not-exist-99999',
                'description' => 'Test',
                'detect_command' => 'this-binary-definitely-does-not-exist-99999 --version',
                'requires_env' => '',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = new LocalAgentDiscovery;
        $detected = $discovery->detect();

        $this->assertEmpty($detected);
    }

    public function test_version_parses_common_patterns(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment');
        }

        config(['local_agents.enabled' => true]);

        // Use 'php' as a test binary — its --version output contains a parseable version
        config(['local_agents.agents' => [
            'test-agent' => [
                'name' => 'Test Agent',
                'binary' => 'php',
                'detect_command' => 'php --version',
                'requires_env' => '',
                'capabilities' => [],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = new LocalAgentDiscovery;
        $version = $discovery->version('test-agent');

        $this->assertNotNull($version);
        $this->assertMatchesRegularExpression('/\d+\.\d+/', $version);
    }

    public function test_cursor_detected_when_binary_found_with_cursor_in_version(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment');
        }

        config(['local_agents.enabled' => true]);

        // Simulate cursor binary using 'php' with a detect_command that outputs "cursor 0.48.1"
        config(['local_agents.agents' => [
            'cursor' => [
                'name' => 'Cursor',
                'binary' => 'php',
                'description' => 'AI coding agent by Cursor (Anysphere)',
                'detect_command' => 'echo "cursor 0.48.1"',
                'requires_env' => 'CURSOR_API_KEY',
                'capabilities' => ['code_generation', 'file_editing'],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = new LocalAgentDiscovery;
        $detected = $discovery->detect();

        $this->assertArrayHasKey('cursor', $detected);
        $this->assertEquals('Cursor', $detected['cursor']['name']);
        // parseVersion extracts the numeric part from "cursor 0.48.1" → "0.48.1"
        $this->assertNotEmpty($detected['cursor']['version']);
    }

    public function test_cursor_rejected_when_binary_found_but_version_is_not_cursor(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment');
        }

        config(['local_agents.enabled' => true]);

        // 'php' binary exists but its version output does not contain "cursor"
        // This simulates a naming collision (e.g. some other tool named 'agent')
        config(['local_agents.agents' => [
            'cursor' => [
                'name' => 'Cursor',
                'binary' => 'php',
                'description' => 'AI coding agent by Cursor (Anysphere)',
                'detect_command' => 'echo "my-other-tool 1.0.0"',
                'requires_env' => 'CURSOR_API_KEY',
                'capabilities' => ['code_generation', 'file_editing'],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = new LocalAgentDiscovery;
        $detected = $discovery->detect();

        // Should be rejected because version output doesn't contain "cursor"
        $this->assertArrayNotHasKey('cursor', $detected);
    }

    public function test_cursor_not_detected_when_binary_missing(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available in this environment');
        }

        config(['local_agents.enabled' => true]);

        config(['local_agents.agents' => [
            'cursor' => [
                'name' => 'Cursor',
                'binary' => 'this-binary-definitely-does-not-exist-cursor-99999',
                'description' => 'AI coding agent by Cursor (Anysphere)',
                'detect_command' => 'this-binary-definitely-does-not-exist-cursor-99999 --version',
                'requires_env' => 'CURSOR_API_KEY',
                'capabilities' => ['code_generation', 'file_editing'],
                'supported_modes' => ['sync'],
            ],
        ]]);

        $discovery = new LocalAgentDiscovery;
        $detected = $discovery->detect();

        $this->assertArrayNotHasKey('cursor', $detected);
        $this->assertEmpty($detected);
    }
}
