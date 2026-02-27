<?php

namespace App\Domain\Tool\DTOs;

readonly class SshExecutionResult
{
    public function __construct(
        public string $output,
        public int $exitCode,
        public int $durationMs,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function toArray(): array
    {
        return [
            'output' => $this->output,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
        ];
    }
}
