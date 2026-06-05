<?php

namespace Tests\Unit\Infrastructure\AI\ModelCatalog;

use App\Infrastructure\AI\Services\ModelCatalog\OpenRouterCatalogAdapter;
use PHPUnit\Framework\TestCase;

class OpenRouterCatalogAdapterTest extends TestCase
{
    private function adapter(): OpenRouterCatalogAdapter
    {
        return new OpenRouterCatalogAdapter;
    }

    public function test_normalizes_pricing_per_million_tokens(): void
    {
        $entries = $this->adapter()->normalize([
            'data' => [[
                'id' => 'google/gemma-4-12b-it',
                'name' => 'Google: Gemma 4 12B',
                'context_length' => 256000,
                'pricing' => ['prompt' => '0.00000006', 'completion' => '0.00000033'],
            ]],
        ]);

        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('google/gemma-4-12b-it', $entry->id);
        $this->assertSame('Google: Gemma 4 12B', $entry->label);
        // 0.00000006 USD/token * 1e6 = 0.06 USD/Mtok
        $this->assertEqualsWithDelta(0.06, $entry->inputUsdPerMtok, 1e-9);
        $this->assertEqualsWithDelta(0.33, $entry->outputUsdPerMtok, 1e-9);
        $this->assertSame(256000, $entry->context);
        $this->assertTrue($entry->priced());
    }

    public function test_free_model_is_priced_at_zero_not_unpriced(): void
    {
        $entries = $this->adapter()->normalize([
            'data' => [[
                'id' => 'meta-llama/llama-3.3-70b-instruct:free',
                'name' => 'Llama 3.3 70B (free)',
                'pricing' => ['prompt' => '0', 'completion' => '0'],
            ]],
        ]);

        $this->assertSame(0.0, $entries[0]->inputUsdPerMtok);
        $this->assertTrue($entries[0]->priced(), 'explicit 0 is priced, not unknown');
    }

    public function test_missing_pricing_block_is_unpriced(): void
    {
        $entries = $this->adapter()->normalize([
            'data' => [['id' => 'x/y', 'name' => 'No Price']],
        ]);

        $this->assertNull($entries[0]->inputUsdPerMtok);
        $this->assertFalse($entries[0]->priced());
    }

    public function test_falls_back_to_id_when_name_missing_and_skips_malformed(): void
    {
        $entries = $this->adapter()->normalize([
            'data' => [
                ['id' => 'a/b'],                  // no name → label = id
                ['name' => 'No ID'],              // no id → skipped
                'garbage',                        // non-array → skipped
            ],
        ]);

        $this->assertCount(1, $entries);
        $this->assertSame('a/b', $entries[0]->id);
        $this->assertSame('a/b', $entries[0]->label);
    }

    public function test_empty_or_malformed_payload_returns_empty(): void
    {
        $this->assertSame([], $this->adapter()->normalize([]));
        $this->assertSame([], $this->adapter()->normalize(['data' => 'nope']));
    }
}
