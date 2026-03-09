<?php

namespace App\Domain\Agent\Services;

use Illuminate\Support\Facades\Process;

/**
 * Executes shell commands inside a Docker container with hard isolation.
 *
 * Enabled when config('agent.bash_sandbox_mode') === 'docker'.
 * Defaults to 'php' mode (no Docker) in development.
 *
 * Security flags applied to every container:
 *   --network none  — blocks all outbound network access
 *   --read-only     — root filesystem is immutable
 *   --tmpfs /tmp    — only writeable area besides the workspace mount
 */
final class DockerSandboxExecutor
{
    public function execute(
        string $command,
        SandboxedWorkspace $workspace,
        int $timeoutSeconds = 30,
    ): array {
        $image = config('agent.sandbox_image', 'python:3.12-alpine');
        $workspaceRoot = $workspace->root();

        $result = Process::timeout($timeoutSeconds)->run([
            'docker', 'run', '--rm',
            '--network', 'none',
            '--memory', '256m',
            '--cpus', '0.5',
            '--read-only',
            '--tmpfs', '/tmp:rw,size=64m',
            '-v', "{$workspaceRoot}:/workspace:rw",
            '--workdir', '/workspace',
            $image,
            'sh', '-c', $command,
        ]);

        return [
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 10_000),
            'stderr' => mb_substr($result->errorOutput(), 0, 2_000),
            'successful' => $result->successful(),
        ];
    }
}
