<?php

namespace App\Domain\Agent\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Executes shell commands inside a Docker container with hard isolation.
 *
 * Enabled when config('agent.bash_sandbox_mode') === 'docker'.
 * Defaults to 'php' mode (no Docker) in development.
 *
 * Security flags applied to every container:
 *   --network none  — blocks all outbound network access (default; overridden by network_policy)
 *   --read-only     — root filesystem is immutable
 *   --tmpfs /tmp    — only writeable area besides the workspace mount
 *
 * When a $networkPolicy is provided with non-empty rules, --network bridge is used instead of
 * --network none to allow Docker default bridge connectivity. Full per-rule iptables enforcement
 * is a future iteration; the policy value is stored and the architecture is in place.
 */
final class DockerSandboxExecutor
{
    /**
     * @param  array<string, string>|null  $env  Environment variables to inject into the container
     * @param  array<string, mixed>|null  $networkPolicy  Per-tool egress policy from Tool::network_policy
     */
    public function execute(
        string $command,
        SandboxedWorkspace $workspace,
        int $timeoutSeconds = 30,
        ?array $env = null,
        ?array $networkPolicy = null,
    ): array {
        $image = config('agent.sandbox_image', 'python:3.12-alpine');
        $workspaceRoot = $workspace->root();

        // Determine network mode: default is fully isolated ("none").
        // When the tool's network_policy has explicit rules we switch to bridge
        // to enable Docker's default routing; per-destination iptables enforcement
        // will be added in a subsequent iteration.
        $hasNetworkRules = ! empty($networkPolicy['rules']);
        $networkMode = $hasNetworkRules ? 'bridge' : 'none';

        if ($hasNetworkRules) {
            Log::warning('DockerSandboxExecutor: network policy not yet enforced at iptables level, using bridge network', [
                'rules_count' => count($networkPolicy['rules']),
                'default_action' => $networkPolicy['default_action'] ?? 'deny',
            ]);
        }

        $dockerArgs = [
            'docker', 'run', '--rm',
            '--network', $networkMode,
            '--memory', '256m',
            '--cpus', '0.5',
            '--read-only',
            '--tmpfs', '/tmp:rw,size=64m',
            '-v', "{$workspaceRoot}:/workspace:rw",
            '--workdir', '/workspace',
        ];

        foreach ($env ?? [] as $key => $value) {
            $dockerArgs[] = '--env';
            $dockerArgs[] = "{$key}={$value}";
        }

        $dockerArgs = array_merge($dockerArgs, [$image, 'sh', '-c', $command]);

        $result = Process::timeout($timeoutSeconds)->run($dockerArgs);

        return [
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 10_000),
            'stderr' => mb_substr($result->errorOutput(), 0, 2_000),
            'successful' => $result->successful(),
        ];
    }
}
