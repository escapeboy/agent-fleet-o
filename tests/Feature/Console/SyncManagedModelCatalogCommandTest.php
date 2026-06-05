<?php

namespace Tests\Feature\Console;

use App\Infrastructure\AI\Services\ManagedPricingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncManagedModelCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Config::set('llm_providers.openrouter.dynamic_catalog', true);
        Config::set('llm_providers.openrouter.models_endpoint', 'https://openrouter.test/api/v1/models');
        Config::set('llm_providers.openrouter.models_endpoint_auth', 'none');
        Config::set('llm_providers.openrouter.catalog_adapter', 'openrouter');
        Config::set('model_catalog.unpriced_fallback', ['input_usd_per_mtok' => 10.0, 'output_usd_per_mtok' => 30.0]);
    }

    public function test_persists_priced_models_to_store(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response([
            'data' => [[
                'id' => 'google/gemma-4-12b-it',
                'name' => 'Gemma 4 12B',
                'pricing' => ['prompt' => '0.00000006', 'completion' => '0.00000033'],
            ]],
        ], 200)]);

        $this->artisan('models:sync-catalog')->assertExitCode(0);

        $pricing = app(ManagedPricingStore::class)->forProvider('openrouter');
        $this->assertArrayHasKey('google/gemma-4-12b-it', $pricing);
        $this->assertEqualsWithDelta(0.06, $pricing['google/gemma-4-12b-it']['input_usd_per_mtok'], 1e-9);
    }

    public function test_unpriced_openrouter_model_gets_conservative_fallback_not_zero(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response([
            'data' => [['id' => 'mystery/model', 'name' => 'Mystery']], // no pricing block
        ], 200)]);

        $this->artisan('models:sync-catalog')->assertExitCode(0);

        $pricing = app(ManagedPricingStore::class)->forProvider('openrouter');
        $this->assertArrayHasKey('mystery/model', $pricing);
        // JSON round-trip via GlobalSetting may return 10 (int) for 10.0 — assert
        // value, not type; CostCalculator casts (float) downstream.
        $this->assertEqualsWithDelta(10.0, $pricing['mystery/model']['input_usd_per_mtok'], 1e-9);
        $this->assertGreaterThan(0, $pricing['mystery/model']['output_usd_per_mtok']);
    }

    public function test_no_op_when_flag_off(): void
    {
        Config::set('model_catalog.enabled', false);
        Http::fake(['*' => Http::response(['data' => [['id' => 'x/y']]], 200)]);

        $this->artisan('models:sync-catalog')->assertExitCode(0);

        $this->assertSame([], app(ManagedPricingStore::class)->forProvider('openrouter'));
        Http::assertNothingSent();
    }
}
