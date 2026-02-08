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
}
