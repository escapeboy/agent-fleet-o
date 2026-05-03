<?php

namespace FleetQ\BorunaAudit\DTOs;

readonly class WorkflowRunResult
{
    public function __construct(
        public string $runId,
        public ?string $bundlePath,
        public string $status,
        public ?array $output,
        public ?array $evidence,
        public ?string $errorMessage,
    ) {}

    public static function success(string $runId, string $bundlePath, array $output, ?array $evidence): self
    {
        return new self(
            runId: $runId,
            bundlePath: $bundlePath,
            status: 'completed',
            output: $output,
            evidence: $evidence,
            errorMessage: null,
        );
    }

    public static function failure(string $runId, string $errorMessage): self
    {
        return new self(
            runId: $runId,
            bundlePath: null,
            status: 'failed',
            output: null,
            evidence: null,
            errorMessage: $errorMessage,
        );
    }
}
