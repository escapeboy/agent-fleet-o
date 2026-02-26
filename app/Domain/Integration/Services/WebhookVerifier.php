<?php

namespace App\Domain\Integration\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Three-layer inbound webhook verification:
 *
 * 1. HMAC-SHA256 signature (timing-safe hash_equals)
 * 2. Timestamp freshness (configurable tolerance, default 5 minutes)
 * 3. Delivery ID idempotency (Redis cache with 24h TTL)
 */
class WebhookVerifier
{
    public function verifyHmac(
        string $rawBody,
        string $receivedSignature,
        string $secret,
        string $prefix = '',
    ): bool {
        $expected = $prefix.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $receivedSignature);
    }

    public function isTimestampFresh(int $timestamp, ?int $toleranceSec = null): bool
    {
        $tolerance = $toleranceSec ?? (int) config('integrations.webhook.timestamp_tolerance', 300);
        $age = abs(time() - $timestamp);

        return $age <= $tolerance;
    }

    public function isAlreadyProcessed(string $deliveryId): bool
    {
        $key = 'webhook_delivery:'.$deliveryId;

        return Cache::store('redis')->has($key);
    }

    public function markAsProcessed(string $deliveryId): void
    {
        $ttl = (int) config('integrations.webhook.replay_protection_ttl', 86400);
        $key = 'webhook_delivery:'.$deliveryId;

        Cache::store('redis')->put($key, 1, $ttl);
    }
}
