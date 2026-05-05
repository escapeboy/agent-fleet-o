<?php

namespace App\Domain\Chatbot\Contracts;

/**
 * Contract for resolving team-level knowledge-graph context for chatbot replies.
 *
 * Owned by FleetQ. Consumers that previously imported
 * `Barsy\Contracts\KnowledgeGraphContextProviderInterface` should switch to this
 * namespace — the Barsy-side autoload path is only available in production
 * deploys, so PHPStan/CI cannot resolve it.
 */
interface KnowledgeGraphContextProviderInterface
{
    /**
     * Build a knowledge-graph context string for the given query, or null when
     * the graph is empty/disabled or no relevant facts are found.
     */
    public function retrieveContext(string $query, ?string $teamId = null): ?string;
}
