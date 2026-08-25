<?php

namespace App\Domain\Agent\Services\Sandbox;

use App\Domain\Agent\Contracts\SandboxDriverInterface;
use Illuminate\Support\Facades\Process;

/**
 * Docker-based sandbox: the historical default.
 *
 * Constraints: --network none, --cap-drop ALL, read-only rootfs, hard mem/CPU
 * caps, workspace mounted read-only. Extracted verbatim from the previous
 * inline AgentSandbox::execute() so behavior stays byte-identical for existing
 * callers when SANDBOX_DRIVER=docker (the default).
 */
final class DockerSandboxDriver implements SandboxDriverInterface
{
    public function execute(string $worktreePath, string|array $command, array $sandboxConfig = []): array
    {
        $image = $sandboxConfig['image'] ?? 'agent-fleet/sandbox:latest';
        $memoryLimit = $sandboxConfig['memory_limit'] ?? '512m';
        $cpuLimit = $sandboxConfig['cpu_limit'] ?? '1';
        $timeoutSeconds = $sandboxConfig['timeout_seconds'] ?? 300;
        $env = $sandboxConfig['env'] ?? [];

        $dockerCmd = [
            'docker', 'run',
            '--rm',
            '--network', 'none',
            '--cap-drop', 'ALL',
            '--read-only',
            '--memory', $memoryLimit,
            '--cpus', $cpuLimit,
            '--workdir', '/workspace',
            '-v', $worktreePath.':/workspace:ro',
        ];

        foreach ($env as $key => $value) {
            $sanitizedValue = str_replace(["\n", "\r", "\0"], '', (string) $value);
            $dockerCmd[] = '-e';
            $dockerCmd[] = $key.'='.$sanitizedValue;
        }

        $dockerCmd[] = $image;

        if (is_array($command)) {
            array_push($dockerCmd, ...$command);
        } else {
            array_push($dockerCmd, '/bin/sh', '-c', $command);
        }

        $result = Process::timeout($timeoutSeconds)->run($dockerCmd);

        return [
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 65_535),
            'stderr' => mb_substr($result->errorOutput(), 0, 16_384),
            'driver' => $this->name(),
        ];
    }

    public function name(): string
    {
        return 'docker';
    }
}
