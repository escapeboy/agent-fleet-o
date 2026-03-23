<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Models\Agent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Provides per-execution Docker process isolation for agents.
 *
 * When enabled (enterprise plan + sandbox_profile configured on agent),
 * wraps the agent's full execution environment in a dedicated container.
 *
 * Sandbox profile shape (stored as JSONB on Agent::sandbox_profile):
 * {
 *   "image": "python:3.12-alpine",   // Docker image — must match ALLOWED_IMAGES allowlist
 *   "memory": "512m",                // Memory limit
 *   "cpus": "1.0",                   // CPU limit
 *   "network": "none"|"bridge",      // Network mode — only these two values are accepted
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
     * Images permitted in sandbox_profile.image.
     * Wildcard (*) matches any tag for that registry path prefix.
     */
    private const ALLOWED_IMAGE_PREFIXES = [
        'python:',
        'node:',
        'ruby:',
        'php:',
        'golang:',
        'rust:',
        'openjdk:',
        'alpine:',
        'ubuntu:',
        'debian:',
    ];

    /** Only these network modes are accepted from user-supplied profiles. */
    private const ALLOWED_NETWORK_MODES = ['none', 'bridge'];

    /**
     * Run a command inside a dedicated sandbox container for this agent.
     *
     * @param  array<string, string>  $env
     * @return array{exit_code: int|null, stdout: string, stderr: string, successful: bool}
     *
     * @throws InvalidArgumentException if sandbox_profile contains disallowed image or network mode
     */
    public function run(Agent $agent, string $command, array $env = []): array
    {
        $profile = array_merge(self::DEFAULTS, $agent->sandbox_profile ?? []);

        $this->validateProfile($profile, $agent->id);

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

    /**
     * Validate that profile fields contain only permitted values.
     *
     * @throws InvalidArgumentException
     */
    private function validateProfile(array $profile, string $agentId): void
    {
        $image = (string) ($profile['image'] ?? '');
        $allowed = false;
        foreach (self::ALLOWED_IMAGE_PREFIXES as $prefix) {
            if (str_starts_with($image, $prefix)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            throw new InvalidArgumentException(
                "sandbox_profile.image '{$image}' is not in the allowed image list for agent {$agentId}. "
                .'Permitted prefixes: '.implode(', ', self::ALLOWED_IMAGE_PREFIXES),
            );
        }

        $network = (string) ($profile['network'] ?? 'none');
        if (! in_array($network, self::ALLOWED_NETWORK_MODES, true)) {
            throw new InvalidArgumentException(
                "sandbox_profile.network '{$network}' is not allowed for agent {$agentId}. "
                .'Permitted values: '.implode(', ', self::ALLOWED_NETWORK_MODES),
            );
        }
    }
}
