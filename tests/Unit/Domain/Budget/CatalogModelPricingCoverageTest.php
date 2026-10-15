<?php

namespace Tests\Unit\Domain\Budget;

use App\Domain\Budget\Services\CostCalculator;
use Tests\TestCase;

/**
 * Every model a team can pick from a static provider catalog must have a
 * pricing row. A missing row makes CostCalculator price the call at $0, so
 * platform-credit teams pay only min_credits_per_call regardless of tokens.
 */
class CatalogModelPricingCoverageTest extends TestCase
{
    public function test_every_static_catalog_model_has_a_pricing_row(): void
    {
        $calc = new CostCalculator;
        $missing = [];

        foreach (config('llm_providers') as $provider => $definition) {
            // Dynamic catalogs are priced from the live endpoint; local CLI agents,
            // bridge and self-hosted HTTP providers are zero-cost and unbilled.
            if (! empty($definition['dynamic_catalog'])
                || ! empty($definition['local'])
                || ! empty($definition['bridge'])
                || ! empty($definition['http_local'])) {
                continue;
            }

            foreach (array_keys($definition['models'] ?? []) as $model) {
                if ($calc->pricing($provider, $model) === null) {
                    $missing[] = "{$provider}/{$model}";
                }
            }
        }

        $this->assertSame([], $missing, 'Catalog models without a llm_pricing row: '.implode(', ', $missing));
    }

    public function test_fable_call_is_billed_by_tokens_not_the_per_call_minimum(): void
    {
        config()->set('llm_pricing.usd_per_credit', 0.01);
        config()->set('llm_pricing.margin_multiplier', 1.30);
        config()->set('llm_pricing.min_credits_per_call', 1);
        config()->set('llm_pricing.max_credits_per_call', null);

        $result = (new CostCalculator)->calculatePlatformCredits(
            provider: 'anthropic',
            model: 'claude-fable-5-1',
            inputTokens: 100_000,
            outputTokens: 10_000,
        );

        // raw = 0.1 * $10 + 0.01 * $50 = $1.50; billable = $1.95 → 195 credits
        $this->assertEqualsWithDelta(1.50, $result['raw_cost_usd'], 0.0001);
        $this->assertSame(195, $result['platform_credits']);
    }
}
