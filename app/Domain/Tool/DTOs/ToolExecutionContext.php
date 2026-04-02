<?php

namespace App\Domain\Tool\DTOs;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Models\Tool;

/**
 * Context passed through the tool execution middleware pipeline.
 */
final class ToolExecutionContext
{
    public function __construct(
        public readonly Tool $tool,
        public readonly string $toolName,
        public array $input,
        public readonly ?Agent $agent,
        public readonly string $teamId,
        public readonly ?string $executionId = null,
        public array $metadata = [],
    ) {}

    public function withInput(array $input): self
    {
        $clone = clone $this;
        $clone->input = $input;

        return $clone;
    }
}
