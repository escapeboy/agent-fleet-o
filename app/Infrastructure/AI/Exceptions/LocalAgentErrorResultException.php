<?php

namespace App\Infrastructure\AI\Exceptions;

use RuntimeException;

/**
 * A local agent (Claude Code) finished but its result reported an error:
 * is_error was set, the subtype was error_* (error_max_turns,
 * error_during_execution) or a usage limit was reached. The output is not
 * a valid answer and must not be stored as one.
 */
class LocalAgentErrorResultException extends RuntimeException
{
    public function __construct(
        public readonly string $agentName,
        public readonly string $subtype,
        public readonly string $detail,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? "Local agent '{$agentName}' reported an error ({$subtype}): ".mb_substr($detail, 0, 500),
        );
    }
}
