<?php

declare(strict_types=1);

namespace App\Domain\Tool\Services;

/**
 * Lazy registry for MCP stdio process handles.
 *
 * Defers MCP server startup to the first actual tool call, avoiding the
 * overhead of spawning processes for tools that are never invoked in a
 * given agent execution.
 *
 * Registered as a singleton so the same handle map survives the full
 * lifetime of a single PHP request / queue job.
 */
class McpHandleRegistry
{
    /** @var array<string, mixed> Map of tool ID → process handle */
    private array $handles = [];

    /**
     * Check whether a handle is already registered for the given tool.
     */
    public function has(string $toolId): bool
    {
        return isset($this->handles[$toolId]);
    }

    /**
     * Retrieve the handle for a tool, or null if not yet registered.
     */
    public function get(string $toolId): mixed
    {
        return $this->handles[$toolId] ?? null;
    }

    /**
     * Register (or replace) the process handle for a tool.
     */
    public function register(string $toolId, mixed $handle): void
    {
        $this->handles[$toolId] = $handle;
    }

    /**
     * Release and discard the handle for a specific tool.
     */
    public function release(string $toolId): void
    {
        unset($this->handles[$toolId]);
    }

    /**
     * Release all registered handles (e.g. at end of agent execution).
     */
    public function releaseAll(): void
    {
        $this->handles = [];
    }
}
