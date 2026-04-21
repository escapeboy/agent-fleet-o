<?php

namespace App\Domain\Chatbot\Contracts;

/**
 * Contract for resolving team-level memory context for chatbot replies.
 *
 * Owned by FleetQ. Consumers that previously imported
 * `Barsy\Contracts\MemoryContextProviderInterface` should switch to this
 * namespace — the Barsy-side alias broke when FleetQ's own test suite
 * attempted to load the class without Barsy's autoload prefix.
 */
interface MemoryContextProviderInterface
{
    /**
     * Build a memory-context string for the given query/role, or null when
     * memory is disabled or no relevant memories are found.
     */
    public function retrieveContext(string $query, string $role, ?string $chatbotId = null): ?string;
}
