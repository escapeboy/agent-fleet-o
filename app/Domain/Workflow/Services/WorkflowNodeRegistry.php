<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Workflow\Contracts\WorkflowNodeHandlerInterface;
use App\Domain\Workflow\DTOs\WorkflowNodeDefinition;

class WorkflowNodeRegistry
{
    /** @var array<string, class-string<WorkflowNodeHandlerInterface>> type => handler class */
    protected array $handlers = [];

    /**
     * Register a custom node handler.
     *
     * @param  class-string<WorkflowNodeHandlerInterface>  $handlerClass
     */
    public function register(string $handlerClass): void
    {
        if (! is_a($handlerClass, WorkflowNodeHandlerInterface::class, true)) {
            throw new \InvalidArgumentException("{$handlerClass} must implement WorkflowNodeHandlerInterface.");
        }

        $definition = $handlerClass::definition();
        $this->handlers[$definition->type] = $handlerClass;
    }

    /**
     * Check if a type has a registered plugin handler.
     */
    public function has(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Get the handler class for a type, or null if not registered.
     *
     * @return class-string<WorkflowNodeHandlerInterface>|null
     */
    public function handlerFor(string $type): ?string
    {
        return $this->handlers[$type] ?? null;
    }

    /**
     * Return all registered node definitions (for the builder UI).
     *
     * @return list<WorkflowNodeDefinition>
     */
    public function definitions(): array
    {
        return array_map(
            fn (string $class) => $class::definition(),
            array_values($this->handlers),
        );
    }

    public function isEmpty(): bool
    {
        return empty($this->handlers);
    }
}
