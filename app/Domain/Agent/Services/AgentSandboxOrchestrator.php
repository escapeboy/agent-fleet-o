<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Models\Agent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Provides per-execution Docker process isolation for agents.
 *
 * When enabled (enterprise plan + sandbox_profile configured on agent),
 * wraps the agent's full execution environment in a dedicated container.
 *
 * Sandbox profile shape (stored as JSONB on Agent::sandbox_profile):
 * {
 *   "image": "python:3.12-alpine",   // Docker image
 *   "memory": "512m",                // Memory limit
 *   "cpus": "1.0",                   // CPU limit
 *   "network": "none",               // Network mode: none|bridge
 *   "timeout": 300,                  // Execution timeout in seconds
 *   "env": {}                        // Additional env vars
 * }
 */
final class AgentSandboxOrchestrator
{
    /** Default sandbox profile values */
    private const DEFAULTS = [
        'image' => 'python:3.12-alpine',
        'memory' => '512m',
        'cpus' => '1.0',
        'network' => 'none',
        'timeout' => 300,
    ];

    /**
     * Run a command inside a dedicated sandbox container for this agent.
     *
     * @param  array<string, string>  $env
     * @return array{exit_code: int|null, stdout: string, stderr: string, successful: bool}
     */
    public function run(Agent $agent, string $command, array $env = []): array
    {
        $profile = array_merge(self::DEFAULTS, $agent->sandbox_profile ?? []);

        $dockerArgs = [
            'docker', 'run', '--rm',
            '--network', $profile['network'],
            '--memory', $profile['memory'],
            '--cpus', $profile['cpus'],
            '--read-only',
            '--tmpfs', '/tmp:rw,size=64m',
        ];

        // Inject env vars
        foreach (array_merge($profile['env'] ?? [], $env) as $key => $value) {
            $dockerArgs[] = '--env';
            $dockerArgs[] = "{$key}={$value}";
        }

        $dockerArgs = array_merge($dockerArgs, [$profile['image'], 'sh', '-c', $command]);

        Log::info('AgentSandboxOrchestrator: running agent in isolated container', [
            'agent_id' => $agent->id,
            'image' => $profile['image'],
            'network' => $profile['network'],
        ]);

        $result = Process::timeout($profile['timeout'])->run($dockerArgs);

        return [
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 10_000),
            'stderr' => mb_substr($result->errorOutput(), 0, 2_000),
            'successful' => $result->successful(),
        ];
    }

    /**
     * Whether process isolation is configured and available for this agent.
     */
    public function isEnabled(Agent $agent): bool
    {
        return ! empty($agent->sandbox_profile);
    }
}
