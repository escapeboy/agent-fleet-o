<?php

namespace App\Domain\Agent\Exceptions;

use RuntimeException;

/**
 * Thrown when an agent's input is too ambiguous to execute safely.
 * Caught by ExecuteAgentAction to trigger the clarification interrupt flow:
 * create ApprovalRequest → transition experiment to AwaitingApproval → return awaiting_clarification execution.
 */
final class ClarificationNeededException extends RuntimeException
{
    public function __construct(
        public readonly string $question,
        public readonly string $agentId,
        public readonly string $experimentId,
        public readonly array $detectedAmbiguities = [],
        public readonly float $ambiguityScore = 0.0,
    ) {
        parent::__construct("Agent requires clarification: {$question}");
    }
}
