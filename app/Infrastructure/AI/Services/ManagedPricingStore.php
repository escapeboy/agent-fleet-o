<?php

namespace App\Infrastructure\AI\Services;

use App\Models\GlobalSetting;

/**
 * Durable store for pricing synced from managed provider catalogs.
 *
 * The sync command (models:sync-catalog) writes normalized per-model pricing
 * here. AiServiceProvider::boot reads it (cheap, no HTTP) and merges it into
 * config('llm_pricing.providers') so CostCalculator::getPricing resolves a
 * real (non-zero) rate for dynamically-discovered models.
 *
 * Backed by GlobalSetting (DB) so it survives deploys/cache flushes — the
 * billing path must not depend on a volatile cache entry.
 *
 * @phpstan-type PricingMap array<string, array<string, array{tier:string, input_usd_per_mtok:float, output_usd_per_mtok:float, last_verified_at:string}>>
 */
class ManagedPricingStore
{
    private const KEY = 'managed_model_pricing';

    /**
     * @return PricingMap  provider => model => pricing entry
     */
    public function all(): array
    {
        $value = GlobalSetting::get(self::KEY, []);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, array{tier:string, input_usd_per_mtok:float, output_usd_per_mtok:float, last_verified_at:string}>
     */
    public function forProvider(string $provider): array
    {
        return $this->all()[$provider] ?? [];
    }

    /**
     * Replace the pricing map for a single provider, leaving other providers intact.
     *
     * @param  array<string, array{tier:string, input_usd_per_mtok:float, output_usd_per_mtok:float, last_verified_at:string}>  $models
     */
    public function putProvider(string $provider, array $models): void
    {
        $all = $this->all();
        $all[$provider] = $models;
        GlobalSetting::set(self::KEY, $all);
    }
}
