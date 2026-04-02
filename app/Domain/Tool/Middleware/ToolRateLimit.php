<?php

namespace App\Domain\Tool\Middleware;

use App\Domain\Tool\Contracts\ToolExecutionMiddlewareInterface;
use App\Domain\Tool\DTOs\ToolExecutionContext;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Rate-limits tool invocations per team.
 */
class ToolRateLimit implements ToolExecutionMiddlewareInterface
{
    public function handle(ToolExecutionContext $context, Closure $next): array
    {
        $key = "tool_exec:{$context->teamId}:{$context->tool->id}";
        $maxPerMinute = $context->tool->config['rate_limit'] ?? 60;

        if (RateLimiter::tooManyAttempts($key, $maxPerMinute)) {
            return [
                'error' => "Rate limit exceeded for tool '{$context->toolName}'. Max {$maxPerMinute}/minute.",
                'blocked_by' => 'rate_limit',
            ];
        }

        RateLimiter::hit($key, 60);

        return $next($context);
    }
}
