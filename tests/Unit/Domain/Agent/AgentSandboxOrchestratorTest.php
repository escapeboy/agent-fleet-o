<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentSandboxOrchestrator;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AgentSandboxOrchestratorTest extends TestCase
{
    private AgentSandboxOrchestrator $orchestrator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->orchestrator = new AgentSandboxOrchestrator;
    }

    public function test_is_enabled_returns_false_when_sandbox_profile_is_null(): void
    {
        $agent = new Agent;
        $agent->sandbox_profile = null;

        $this->assertFalse($this->orchestrator->isEnabled($agent));
    }

    public function test_is_enabled_returns_false_when_sandbox_profile_is_empty_array(): void
    {
        $agent = new Agent;
        $agent->sandbox_profile = [];

        $this->assertFalse($this->orchestrator->isEnabled($agent));
    }

    public function test_is_enabled_returns_true_when_sandbox_profile_is_set(): void
    {
        $agent = new Agent;
        $agent->sandbox_profile = ['image' => 'python:3.12-alpine'];

        $this->assertTrue($this->orchestrator->isEnabled($agent));
    }

    public function test_run_merges_defaults_with_agent_profile_and_returns_correct_shape(): void
    {
        Process::fake();

        $agent = new Agent;
        $agent->id = 'test-agent-id';
        $agent->sandbox_profile = ['image' => 'node:20-alpine', 'memory' => '256m'];

        $result = $this->orchestrator->run($agent, 'echo hello world');

        // Verify the result shape
        $this->assertArrayHasKey('exit_code', $result);
        $this->assertArrayHasKey('stdout', $result);
        $this->assertArrayHasKey('stderr', $result);
        $this->assertArrayHasKey('successful', $result);
        $this->assertSame(0, $result['exit_code']);
        $this->assertTrue($result['successful']);

        // Verify docker was called with custom image and memory (command is an array)
        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [];

            return in_array('docker', $cmd)
                && in_array('node:20-alpine', $cmd)
                && in_array('256m', $cmd);
        });
    }

    public function test_run_uses_default_image_when_not_specified_in_profile(): void
    {
        Process::fake();

        $agent = new Agent;
        $agent->id = 'test-agent-id';
        $agent->sandbox_profile = ['memory' => '128m'];

        $this->orchestrator->run($agent, 'true');

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [];

            return in_array('python:3.12-alpine', $cmd);
        });
    }

    public function test_run_injects_env_vars(): void
    {
        Process::fake();

        $agent = new Agent;
        $agent->id = 'test-agent-id';
        $agent->sandbox_profile = ['image' => 'alpine:3.19'];

        $this->orchestrator->run($agent, 'printenv', ['MY_VAR' => 'my_value']);

        Process::assertRan(function ($process) {
            $cmd = is_array($process->command) ? $process->command : [];

            return in_array('MY_VAR=my_value', $cmd);
        });
    }

    public function test_run_throws_for_disallowed_image(): void
    {
        $agent = new Agent;
        $agent->id = 'test-agent-id';
        $agent->sandbox_profile = ['image' => 'attacker/malicious:latest'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not in the allowed image list/');

        $this->orchestrator->run($agent, 'id');
    }

    public function test_run_throws_for_disallowed_network_mode(): void
    {
        $agent = new Agent;
        $agent->id = 'test-agent-id';
        $agent->sandbox_profile = ['image' => 'python:3.12-alpine', 'network' => 'host'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not allowed/');

        $this->orchestrator->run($agent, 'id');
    }

    public function test_run_truncates_stdout_at_10000_characters(): void
    {
        $longOutput = str_repeat('a', 15_000);

        Process::fake(fn () => FakeProcessResult::class);
        Process::fake([
            '*' => Process::result($longOutput, '', 0),
        ]);

        $agent = new Agent;
        $agent->id = 'test-agent-id';
        $agent->sandbox_profile = ['image' => 'alpine:3.19'];

        $result = $this->orchestrator->run($agent, 'cat bigfile');

        $this->assertSame(10_000, mb_strlen($result['stdout']));
    }
}
