<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Memory\Services\MemoryContextInjector;
use Closure;

/**
 * Injects relevant memories from past executions into the agent's system prompt.
 * Replaces the direct MemoryContextInjector call in buildAgentSystemPrompt().
 */
class InjectMemoryContext
{
    public function __construct(
        private readonly MemoryContextInjector $injector,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        $memoryContext = $this->injector->buildContext(
            agentId: $ctx->agent->id,
            input: $ctx->input,
            projectId: $ctx->project?->id,
            teamId: $ctx->teamId,
        );

        if ($memoryContext) {
            $ctx->systemPromptParts[] = $memoryContext;
        }

        return $next($ctx);
    }
}
