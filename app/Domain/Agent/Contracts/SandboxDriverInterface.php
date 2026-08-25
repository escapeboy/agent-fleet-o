<?php

namespace App\Domain\Agent\Contracts;

/**
 * Backend for executing untrusted experiment/agent code in isolation.
 *
 * Implementations MUST guarantee: no host filesystem access outside the
 * mounted workspace, no host process visibility, and a hard wall-clock
 * timeout enforced independent of the caller.
 */
interface SandboxDriverInterface
{
    /**
     * @param  string|array<int,string>  $command  Shell string or pre-split argv
     * @param  array{image?: string, memory_limit?: string, cpu_limit?: string, timeout_seconds?: int, env?: array<string,string>, region?: string}  $sandboxConfig
     * @return array{exit_code: int|null, stdout: string, stderr: string, driver?: string, cost_estimate_usd?: float, duration_ms?: int, correlation_id?: string}
     */
    public function execute(string $worktreePath, string|array $command, array $sandboxConfig = []): array;

    /**
     * Short identifier for audit logs (e.g. "docker", "modal").
     */
    public function name(): string;
}
