<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services;

use App\Mcp\ErrorCode;

/**
 * Immutable result of ErrorTranslator::translate().
 *
 * Customer-facing fields:
 *   - $code          stable internal code (e.g. 'rate_limit') — safe for analytics
 *   - $message       translated human message in the requested locale
 *   - $actions       list of RecommendedAction; first is the highest-confidence next step
 *
 * Diagnostic fields (kept for transparency, but should NOT be the primary surface):
 *   - $technicalMessage  the raw exception/error string we translated FROM
 *   - $matched           whether a dictionary entry matched (false = generic fallback)
 *   - $mcpErrorCode      the App\Mcp\ErrorCode for retryable hint propagation
 *   - $retryable         convenience accessor; mirrors $mcpErrorCode->isRetryable()
 */
final readonly class ErrorTranslation
{
    /**
     * @param  list<RecommendedAction>  $actions
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $actions,
        public string $technicalMessage,
        public bool $matched,
        public ErrorCode $mcpErrorCode,
        public bool $retryable,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'actions' => array_map(fn (RecommendedAction $a) => $a->toArray(), $this->actions),
            'technical_message' => $this->technicalMessage,
            'matched' => $this->matched,
            'mcp_error_code' => $this->mcpErrorCode->value,
            'retryable' => $this->retryable,
        ];
    }
}
