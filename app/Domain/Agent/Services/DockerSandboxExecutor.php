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
 * Network policy rules are stored and validated but per-destination iptables enforcement is a
 * future iteration. Until then, --network none is always used regardless of policy rules to
 * maintain the safe-by-default posture (no outbound access).
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

        // Always use --network none until per-destination iptables enforcement ships.
        // network_policy rules are stored for future use but do NOT grant bridge access yet —
        // switching to bridge before iptables enforcement is in place would invert the
        // security posture (no-policy = isolated, has-policy = fully open), which is worse.
        $networkMode = 'none';

        if (! empty($networkPolicy['rules'])) {
            Log::info('DockerSandboxExecutor: network_policy rules present but iptables enforcement not yet active; container stays on --network none', [
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
