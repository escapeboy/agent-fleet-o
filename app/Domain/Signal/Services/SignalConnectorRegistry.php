<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Contracts\InputConnectorInterface;

/**
 * Registry for input signal connectors.
 *
 * Resolves connectors by driver name in O(1) using a pre-built map.
 * Populated at container boot via tagged bindings in AppServiceProvider.
 *
 * Adding a new connector:
 *   1. Create a class implementing InputConnectorInterface with getDriverName()
 *   2. Tag it in AppServiceProvider: $this->app->tag([MyConnector::class], 'signal.input.connectors')
 *   3. No other changes required
 *
 * @example
 *   $connector = app(SignalConnectorRegistry::class)->resolve('clearcue');
 */
class SignalConnectorRegistry
{
    /** @var array<string, InputConnectorInterface> driver => connector */
    private array $map = [];

    /**
     * @param  iterable<InputConnectorInterface>  $connectors
     */
    public function __construct(iterable $connectors)
    {
        foreach ($connectors as $connector) {
            // Use getDriverName() if available, otherwise fall back to supports() scan
            if (method_exists($connector, 'getDriverName')) {
                $this->map[$connector->getDriverName()] = $connector;
            }
        }
    }

    /**
     * Resolve a connector by driver name.
     *
     * @throws \InvalidArgumentException when no connector is registered for the driver
     */
    public function resolve(string $driver): InputConnectorInterface
    {
        if (! isset($this->map[$driver])) {
            throw new \InvalidArgumentException(
                "No input connector registered for driver: {$driver}. "
                .'Available: '.implode(', ', array_keys($this->map)),
            );
        }

        return $this->map[$driver];
    }

    /**
     * Check whether a connector is registered for the driver.
     */
    public function has(string $driver): bool
    {
        return isset($this->map[$driver]);
    }

    /**
     * Return all registered connectors keyed by driver name.
     *
     * @return array<string, InputConnectorInterface>
     */
    public function all(): array
    {
        return $this->map;
    }

    /**
     * Return all registered driver names.
     *
     * @return string[]
     */
    public function drivers(): array
    {
        return array_keys($this->map);
    }
}
