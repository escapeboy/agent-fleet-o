<?php

namespace App\Domain\Tool\Contracts;

use App\Domain\Tool\DTOs\ToolExecutionContext;
use Closure;

/**
 * Middleware for the tool execution pipeline.
 *
 * Follows the same onion model as PrismAiGateway middleware:
 * each middleware wraps the next, can inspect/modify the context
 * before execution, and inspect/modify the result after.
 */
interface ToolExecutionMiddlewareInterface
{
    /**
     * @param  Closure(ToolExecutionContext): array  $next
     * @return array The tool execution result
     */
    public function handle(ToolExecutionContext $context, Closure $next): array;
}
