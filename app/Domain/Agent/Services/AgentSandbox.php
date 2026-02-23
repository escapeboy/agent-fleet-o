<?php

namespace App\Domain\Agent\Services;

use Illuminate\Support\Facades\Process;

/**
 * Executes scripts inside a Docker sandbox with strict security constraints:
 * - No network access (--network none)
 * - No Linux capabilities (--cap-drop ALL)
 * - Read-only root filesystem
 * - Memory and CPU hard limits
 *
 * The workspace is mounted read-only to prevent the sandbox from modifying
 * the worktree directly. Scripts should write output to stdout/stderr only.
 */
class AgentSandbox
{
    /**
     * @param  string|array  $command  Shell string or pre-split array command
     * @param  array{image?: string, memory_limit?: string, cpu_limit?: string, timeout_seconds?: int, env?: array<string,string>}  $sandboxConfig
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
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

        // Inject environment variables one by one to avoid shell expansion
        foreach ($env as $key => $value) {
            $sanitizedValue = str_replace(["\n", "\r", "\0"], '', (string) $value);
            $dockerCmd[] = '-e';
            $dockerCmd[] = $key.'='.$sanitizedValue;
        }

        $dockerCmd[] = $image;

        // Append the user-supplied command
        if (is_array($command)) {
            array_push($dockerCmd, ...$command);
        } else {
            // Wrap string commands in a shell to allow pipes/redirects
            array_push($dockerCmd, '/bin/sh', '-c', $command);
        }

        $result = Process::timeout($timeoutSeconds)->run($dockerCmd);

        return [
            'exit_code' => $result->exitCode(),
            'stdout' => mb_substr($result->output(), 0, 65_535),
            'stderr' => mb_substr($result->errorOutput(), 0, 16_384),
        ];
    }
}
