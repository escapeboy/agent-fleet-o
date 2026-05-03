<?php

namespace FleetQ\BorunaAudit\Services;

use FleetQ\BorunaAudit\Exceptions\BorunaQuotaExceeded;
use Illuminate\Support\Facades\Redis;

class QuotaEnforcer
{
    public function checkAndIncrement(string $tenantId): void
    {
        $limit = $this->resolveLimit($tenantId);

        if ($limit === 'unlimited') {
            return;
        }

        $key = $this->redisKey($tenantId);
        $expiry = now()->endOfMonth()->timestamp;

        // Atomically increment first; if the result exceeds the limit, immediately
        // decrement back. This avoids TOCTOU while being self-healing: a brief
        // overshoot is corrected within the same request before any work happens.
        $newCount = (int) Redis::incr($key);
        Redis::expireat($key, $expiry);

        if ($newCount > $limit) {
            Redis::decr($key);
            throw new BorunaQuotaExceeded($tenantId, $newCount - 1, $limit);
        }

        if ($newCount >= (int) ($limit * 0.8)) {
            logger()->warning('Boruna quota at 80% for tenant', ['tenant_id' => $tenantId, 'used' => $newCount, 'limit' => $limit]);
        }
    }

    public function usage(string $tenantId): array
    {
        $key = $this->redisKey($tenantId);
        $used = (int) Redis::get($key) ?: 0;
        $limit = $this->resolveLimit($tenantId);

        return ['used' => $used, 'limit' => $limit];
    }

    private function redisKey(string $tenantId): string
    {
        return 'boruna:quota:'.$tenantId.':'.now()->format('Y-m');
    }

    private function resolveLimit(string $tenantId): int|string
    {
        // Per-tenant DB setting overrides global config
        $setting = \DB::table('boruna_tenant_settings')
            ->where('team_id', $tenantId)
            ->value('quota_per_month');

        if ($setting !== null) {
            return (int) $setting;
        }

        $global = config('boruna_audit.default_quota_per_month', 'unlimited');

        if ($global === 'unlimited') {
            return 'unlimited';
        }

        return (int) $global;
    }
}
