<?php

namespace App\Domain\Agent\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent repeats the exact same tool call sequence a critical number of times.
 *
 * This is distinct from ToolLoopCriticalException (which counts total steps).
 * A semantic loop means the agent is stuck calling the same tools with the same arguments
 * without making progress, regardless of how many total steps have run.
 *
 * Configure thresholds via config('agent.tool_loop.semantic_critical_threshold').
 */
final class ToolLoopSemanticException extends RuntimeException
{
    public function __construct(
        public readonly int $repeatCount,
        public readonly int $threshold,
        public readonly string $agentId,
    ) {
        parent::__construct(
            "Agent semantic tool loop detected: identical tool call sequence repeated {$repeatCount} times (max {$threshold}). Agent ID: {$agentId}",
        );
    }
}
