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
}
