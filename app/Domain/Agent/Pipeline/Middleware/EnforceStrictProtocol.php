<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use Closure;

class EnforceStrictProtocol
{
    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        // When strict mode is off this middleware is a no-op.
        // Actual audit record creation happens in ExecuteAgentAction after the response,
        // using $agent->strict_mode as the gate — this middleware has no side effects.
        return $next($ctx);
    }
}
