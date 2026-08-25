<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Contracts\SandboxDriverInterface;

/**
 * Facade over the configured sandbox driver.
 *
 * Historically wrapped Docker directly; the Docker implementation now lives in
 * Sandbox\DockerSandboxDriver and additional backends (Modal Sandboxes via
 * Sandbox\ModalSandboxDriver) can be selected with SANDBOX_DRIVER.
 *
 * All previous callers (Skill\Actions\ExecuteCodeExecutionSkillAction and
 * warm-build) keep type-hinting AgentSandbox — the only surface change is
 * that execution now goes through the resolved driver.
 */
class AgentSandbox
{
    public function __construct(private readonly SandboxDriverInterface $driver) {}

    /**
     * @param  string|array<int,string>  $command  Shell string or pre-split argv
     * @param  array{image?: string, memory_limit?: string, cpu_limit?: string, timeout_seconds?: int, env?: array<string,string>, region?: string}  $sandboxConfig
     * @return array{exit_code: int|null, stdout: string, stderr: string}
     */
    public function execute(string $worktreePath, string|array $command, array $sandboxConfig = []): array
    {
        return $this->driver->execute($worktreePath, $command, $sandboxConfig);
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }
}
