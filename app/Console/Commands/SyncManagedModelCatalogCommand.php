<?php

namespace App\Console\Commands;

use App\Infrastructure\AI\Services\ManagedModelDiscovery;
use App\Infrastructure\AI\Services\ManagedPricingStore;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Refresh the live model catalog + pricing for every managed multi-model
 * provider flagged `dynamic_catalog`, persisting prices to ManagedPricingStore
 * so CostCalculator resolves real rates. In the cloud edition it also rotates an
 * audit pricing snapshot (SOURCE_AUTO_SYNC) when the snapshot service is bound.
 *
 * Schedule daily; also invokable from the Settings "Refresh models" action and
 * the model_catalog MCP tool.
 */
class SyncManagedModelCatalogCommand extends Command
{
    protected $signature = 'models:sync-catalog {--provider= : Limit to a single provider key}';

    protected $description = 'Sync live model catalogs + pricing from managed multi-model providers';

    /** Cloud-only snapshot service — resolved by string so base never hard-depends on it. */
    private const SNAPSHOT_SERVICE = '\\Cloud\\Domain\\Budget\\Services\\PricingSnapshotService';

    public function handle(ManagedModelDiscovery $discovery, ManagedPricingStore $store): int
    {
        if (! config('model_catalog.enabled')) {
            $this->warn('Dynamic model catalog sync is disabled (MANAGED_MODEL_CATALOG_SYNC=false). Nothing to do.');

            return self::SUCCESS;
        }

        $only = $this->option('provider');
        $today = Carbon::now()->toDateString();
        $fallback = config('model_catalog.unpriced_fallback', ['input_usd_per_mtok' => 10.0, 'output_usd_per_mtok' => 30.0]);

        $syncedPricing = [];

        foreach (config('llm_providers', []) as $key => $provider) {
            if (empty($provider['dynamic_catalog'])) {
                continue;
            }
            if ($only && $only !== $key) {
                continue;
            }

            $entries = $discovery->discover($key, null, force: true);

            if ($entries === []) {
                $this->warn("  {$key}: endpoint unreachable or empty — keeping existing catalog.");

                continue;
            }

            $adapter = config("llm_providers.{$key}.catalog_adapter");
            $pricing = [];

            foreach ($entries as $entry) {
                if ($entry->priced()) {
                    $pricing[$entry->id] = $entry->toPricingEntry($today);
                } elseif ($adapter === 'openrouter') {
                    // OpenRouter has no config wildcard — never leave a model unpriced
                    // (would bill $0). Apply the conservative fallback rate.
                    $pricing[$entry->id] = [
                        'tier' => 'default',
                        'input_usd_per_mtok' => (float) $fallback['input_usd_per_mtok'],
                        'output_usd_per_mtok' => (float) $fallback['output_usd_per_mtok'],
                        'last_verified_at' => $today,
                    ];
                }
                // Generic OpenAI-compatible providers keep their config pricing.
            }

            $store->putProvider($key, $pricing);
            $syncedPricing[$key] = $pricing;
            $this->info("  {$key}: ".count($entries).' models, '.count($pricing).' priced.');
        }

        $this->recordAuditSnapshot($syncedPricing);

        return self::SUCCESS;
    }

    /**
     * Cloud edition: rotate a pricing snapshot for audit parity with the manual
     * editor / Helicone sync. No-op in community edition.
     *
     * @param  array<string, array<string, array<string, mixed>>>  $syncedPricing
     */
    private function recordAuditSnapshot(array $syncedPricing): void
    {
        if ($syncedPricing === [] || ! class_exists(self::SNAPSHOT_SERVICE)) {
            return;
        }

        try {
            $service = app(self::SNAPSHOT_SERVICE);
            $current = $service->current();
            $rates = $current->rates ?? ['providers' => []];

            foreach ($syncedPricing as $provider => $models) {
                $rates['providers'][$provider] = array_merge($rates['providers'][$provider] ?? [], $models);
            }

            $snapshotModel = '\\Cloud\\Domain\\Budget\\Models\\LlmPricingSnapshot';
            $source = defined("{$snapshotModel}::SOURCE_AUTO_SYNC")
                ? constant("{$snapshotModel}::SOURCE_AUTO_SYNC")
                : 'auto_sync';

            $service->recordChange($rates, $source, null, 'Managed model catalog sync');
        } catch (\Throwable $e) {
            $this->warn('  Audit snapshot skipped: '.$e->getMessage());
        }
    }
}
