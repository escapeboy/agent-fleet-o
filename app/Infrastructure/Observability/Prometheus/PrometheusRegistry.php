<?php

declare(strict_types=1);

namespace App\Infrastructure\Observability\Prometheus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\APC;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as PrometheusRedisStorage;

/**
 * Wraps Prometheus CollectorRegistry with FleetQ-aware adapter selection.
 *
 * Adapter resolution:
 *   - testing env → InMemory (fast, deterministic, no side effects)
 *   - storage=redis → Redis adapter using Laravel's 'cache' connection params
 *   - storage=apc → APC adapter (requires apcu PHP extension)
 *   - storage=in_memory → InMemory
 *
 * When `observability.prometheus.enabled=false`, the registry still works but
 * the MetricEmitter routes calls into a noop façade — this is preferred over
 * returning a separate noop registry because tests still want to assert
 * counters were *called* (and the InMemory adapter handles that cheaply).
 */
final class PrometheusRegistry
{
    private ?CollectorRegistry $registry = null;

    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function registry(): CollectorRegistry
    {
        return $this->registry ??= $this->buildRegistry();
    }

    /**
     * Reset for tests. Wipes all in-memory adapter data and recreates the
     * registry. No effect on Redis/APC adapters (use the wipe* methods on
     * those adapters in your test setUp if needed).
     */
    public function reset(): void
    {
        if ($this->registry !== null) {
            // Force fresh registry on next call.
            $this->registry = null;
        }
    }

    private function buildRegistry(): CollectorRegistry
    {
        if (app()->environment('testing')) {
            // Tests always use InMemory regardless of config to avoid leakage.
            return new CollectorRegistry(new InMemory);
        }

        $adapter = match ((string) $this->config->get('observability.prometheus.storage', 'redis')) {
            'apc' => new APC,
            'in_memory' => new InMemory,
            default => $this->buildRedisAdapter(),
        };

        return new CollectorRegistry($adapter);
    }

    private function buildRedisAdapter(): PrometheusRedisStorage
    {
        $connection = (string) $this->config->get('observability.prometheus.redis_connection', 'cache');
        $redisConfig = $this->config->get("database.redis.{$connection}", []);

        $options = [];
        if (isset($redisConfig['host'])) {
            $options['host'] = (string) $redisConfig['host'];
        }
        if (isset($redisConfig['port'])) {
            $options['port'] = (int) $redisConfig['port'];
        }
        if (isset($redisConfig['password']) && $redisConfig['password'] !== null && $redisConfig['password'] !== '') {
            $options['password'] = (string) $redisConfig['password'];
        }
        if (isset($redisConfig['database'])) {
            $options['database'] = (int) $redisConfig['database'];
        }

        // Allow override of the prefix to keep prom keys distinct from Laravel cache keys.
        $options['prefix'] = (string) $this->config->get('observability.prometheus.redis_prefix', 'fleetq_prom_');

        return new PrometheusRedisStorage($options);
    }
}
