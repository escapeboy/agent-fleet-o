<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Contracts\ToolExecutionMiddlewareInterface;
use App\Domain\Tool\DTOs\ToolExecutionContext;
use App\Domain\Tool\Middleware\ToolAuditLog;
use App\Domain\Tool\Middleware\ToolInputValidation;
use App\Domain\Tool\Middleware\ToolRateLimit;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolMiddlewareConfig;
use Closure;

/**
 * Executes a tool call through a configurable middleware pipeline.
 *
 * Follows the same onion model as PrismAiGateway::buildPipeline():
 * middleware stack is reversed so the first middleware executes first,
 * and each wraps the next.
 */
class ToolMiddlewarePipeline
{
    /**
     * Built-in middleware that always runs (in this order).
     */
    private const BUILT_IN = [
        ToolRateLimit::class,
        ToolInputValidation::class,
        ToolAuditLog::class,
    ];

    /**
     * Execute a tool call through the middleware pipeline.
     *
     * @param  Closure(ToolExecutionContext): array  $handler  The actual tool execution
     */
    public function execute(ToolExecutionContext $context, Closure $handler): array
    {
        $middleware = $this->resolveMiddleware($context->tool);
        $pipeline = $this->buildPipeline($middleware, $handler);

        return $pipeline($context);
    }

    /**
     * Resolve middleware stack: built-in + tool-specific configured middleware.
     *
     * @return array<ToolExecutionMiddlewareInterface>
     */
    private function resolveMiddleware(Tool $tool): array
    {
        $stack = [];

        // Built-in middleware
        foreach (self::BUILT_IN as $class) {
            $stack[] = app($class);
        }

        // Tool-specific configured middleware
        $configs = ToolMiddlewareConfig::where('tool_id', $tool->id)
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();

        foreach ($configs as $config) {
            if (class_exists($config->middleware_class) && is_a($config->middleware_class, ToolExecutionMiddlewareInterface::class, true)) {
                $stack[] = app($config->middleware_class);
            }
        }

        return $stack;
    }

    /**
     * Build the onion pipeline — same pattern as PrismAiGateway.
     *
     * @param  array<ToolExecutionMiddlewareInterface>  $middleware
     * @param  Closure(ToolExecutionContext): array  $handler
     * @return Closure(ToolExecutionContext): array
     */
    private function buildPipeline(array $middleware, Closure $handler): Closure
    {
        return array_reduce(
            array_reverse($middleware),
            fn (Closure $next, ToolExecutionMiddlewareInterface $mw) => fn (ToolExecutionContext $ctx) => $mw->handle($ctx, $next),
            $handler,
        );
    }
}
