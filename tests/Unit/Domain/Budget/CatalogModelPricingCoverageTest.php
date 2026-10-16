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

    public function test_document_ocr_openrouter_default_is_priced(): void
    {
        $calc = new CostCalculator;

        // Cloud ProcessDocumentOcrAction sends the dashed id; OpenRouter lists the dotted one.
        foreach (['anthropic/claude-sonnet-4-5', 'anthropic/claude-sonnet-4.5'] as $model) {
            $this->assertGreaterThan(0, $calc->calculateCost('openrouter', $model, 1_000_000, 0), $model);
        }
    }

    public function test_catalog_display_prices_equal_the_pricing_rows(): void
    {
        // The model pickers show llm_providers input_cost/output_cost; billing uses
        // llm_pricing. They drifted (gpt-4o shown at 5/15, billed at 2.50/10).
        $calc = new CostCalculator;
        $drift = [];

        foreach (config('llm_providers') as $provider => $definition) {
            foreach ($definition['models'] ?? [] as $model => $row) {
                $rows = config('llm_pricing.providers', [])[$provider] ?? [];
                if (! isset($rows[$model])) {
                    continue;
                }
                $pricing = $calc->pricing($provider, $model);
                $shown = [(float) $row['input_cost'], (float) $row['output_cost']];
                $billed = [(float) $pricing['input_usd_per_mtok'], (float) $pricing['output_usd_per_mtok']];
                if ($shown !== $billed) {
                    $drift[] = "{$provider}/{$model} shown ".implode('/', $shown).' billed '.implode('/', $billed);
                }
            }
        }

        $this->assertSame([], $drift, 'Catalog display prices differ from llm_pricing: '.implode('; ', $drift));
    }

    public function test_gemini_2_5_pro_is_priced_at_the_published_rate(): void
    {
        // ai.google.dev/gemini-api/docs/pricing (2026-10-15), paid tier, prompts <= 200k:
        // input $1.25, output $10.00 per 1M tokens.
        $pricing = (new CostCalculator)->pricing('google', 'gemini-2.5-pro');

        $this->assertSame(1.25, (float) $pricing['input_usd_per_mtok']);
        $this->assertSame(10.00, (float) $pricing['output_usd_per_mtok']);
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
