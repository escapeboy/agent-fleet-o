<?php

namespace App\Domain\Tool\Middleware;

use App\Domain\Tool\Contracts\ToolExecutionMiddlewareInterface;
use App\Domain\Tool\DTOs\ToolExecutionContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Logs tool invocations for audit trail.
 */
class ToolAuditLog implements ToolExecutionMiddlewareInterface
{
    public function handle(ToolExecutionContext $context, Closure $next): array
    {
        $startTime = microtime(true);

        Log::info('ToolMiddleware: tool invocation started', [
            'tool_id' => $context->tool->id,
            'tool_name' => $context->toolName,
            'agent_id' => $context->agent?->id,
            'team_id' => $context->teamId,
        ]);

        $result = $next($context);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        Log::info('ToolMiddleware: tool invocation completed', [
            'tool_id' => $context->tool->id,
            'tool_name' => $context->toolName,
            'duration_ms' => $duration,
            'success' => ! isset($result['error']),
        ]);

        return $result;
    }
}
