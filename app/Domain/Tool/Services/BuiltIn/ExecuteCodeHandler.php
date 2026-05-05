<?php

namespace App\Domain\Tool\Services\BuiltIn;

use App\Domain\Agent\Services\DockerSandboxExecutor;
use App\Domain\Agent\Services\SandboxedWorkspace;
use Illuminate\Support\Str;

class ExecuteCodeHandler
{
    public function __construct(private readonly DockerSandboxExecutor $executor) {}

    public function execute(string $code, int $timeoutSeconds = 30, ?SandboxedWorkspace $workspace = null): array
    {
        if ($workspace === null) {
            $executionId = Str::uuid()->toString();
            $workspace = new SandboxedWorkspace($executionId, 'execute_code', 'platform');
        }

        $command = sprintf('python3 -c %s', escapeshellarg($code));
        $timeout = min($timeoutSeconds, 120);

        return $this->executor->execute($command, $workspace, $timeout);
    }
}
