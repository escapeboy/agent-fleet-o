<?php

namespace App\Domain\Tool\Exceptions;

/**
 * Thrown when a tool with result_as_answer=true produces output.
 * The tool result becomes the agent's final answer, skipping LLM summarization.
 */
class ResultAsAnswerException extends \RuntimeException
{
    public function __construct(
        public readonly mixed $toolResult,
        public readonly string $toolName,
    ) {
        parent::__construct("Tool '{$toolName}' returned result_as_answer.");
    }
}
