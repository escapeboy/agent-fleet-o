<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Services\ManagedModelDiscovery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManagedModelDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Config::set('llm_providers.openrouter.dynamic_catalog', true);
        Config::set('llm_providers.openrouter.models_endpoint', 'https://openrouter.test/api/v1/models');
        Config::set('llm_providers.openrouter.models_endpoint_auth', 'none');
        Config::set('llm_providers.openrouter.catalog_adapter', 'openrouter');
        Config::set('model_catalog.cache_ttl', 3600);
        Config::set('model_catalog.timeout', 5);
    }

    public function test_discovers_and_normalizes_models(): void
    {
        Http::fake(['*' => Http::response([
            'data' => [[
                'id' => 'google/gemma-4-12b-it',
                'name' => 'Gemma 4 12B',
                'pricing' => ['prompt' => '0.00000006', 'completion' => '0.00000033'],
            ]],
        ], 200)]);

        $entries = (new ManagedModelDiscovery)->discover('openrouter');

        $this->assertCount(1, $entries);
        $this->assertSame('google/gemma-4-12b-it', $entries[0]->id);
        $this->assertEqualsWithDelta(0.06, $entries[0]->inputUsdPerMtok, 1e-9);
    }

    public function test_caches_result_no_second_http_call(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'a/b']]], 200)]);

        $svc = new ManagedModelDiscovery;
        $svc->discover('openrouter');
        $svc->discover('openrouter');

        Http::assertSentCount(1);
    }

    public function test_unreachable_endpoint_returns_empty(): void
    {
        Http::fake(['*' => Http::response(null, 500)]);

        $this->assertSame([], (new ManagedModelDiscovery)->discover('openrouter'));
    }

    public function test_bust_cache_forces_refetch(): void
    {
        Http::fake(['*' => Http::response(['data' => [['id' => 'a/b']]], 200)]);

        $svc = new ManagedModelDiscovery;
        $svc->discover('openrouter');
        $svc->bustCache('openrouter');
        $svc->discover('openrouter');

        Http::assertSentCount(2);
    }

    public function test_missing_endpoint_returns_empty(): void
    {
        Config::set('llm_providers.openrouter.models_endpoint', null);

        $this->assertSame([], (new ManagedModelDiscovery)->discover('openrouter'));
    }
}
