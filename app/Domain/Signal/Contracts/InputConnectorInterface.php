<?php

namespace App\Domain\Signal\Contracts;

use App\Domain\Signal\Models\Signal;

interface InputConnectorInterface
{
    /**
     * Poll the source for new signals.
     *
     * @return Signal[] Array of ingested signals (excludes duplicates/blacklisted)
     */
    public function poll(array $config): array;

    public function supports(string $driver): bool;

    /**
     * Return the primary driver name for registry-based O(1) resolution.
     * Default implementation delegates to the class name as a fallback.
     *
     * Implementing this method is optional but recommended for new connectors.
     */
    // public function getDriverName(): string;

    /**
     * Normalize vendor-specific payload to the FleetQ signal field structure.
     * Called during batch processing when a unified schema is needed.
     *
     * Implementing this method is optional — connectors that do not need
     * batch normalization (webhook-only connectors) can omit it.
     */
    // public function normalizeSignal(array $raw): array;
}
