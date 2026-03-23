<?php

namespace App\Domain\Agent\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent's LLM step count exceeds the critical threshold.
 * Caught by ExecuteAgentAction's generic Throwable handler, which records a
 * failed AgentExecution and surfaces the error to the caller / experiment stage.
 *
 * Configure thresholds via config('agent.tool_loop.critical_threshold').
 */
final class ToolLoopCriticalException extends RuntimeException
{
    public function __construct(
        public readonly int $stepsCount,
        public readonly int $threshold,
        public readonly string $agentId,
    ) {
        parent::__construct(
            "Agent tool loop exceeded critical threshold: {$stepsCount} steps (max {$threshold}). Agent ID: {$agentId}",
        );
    }
}
