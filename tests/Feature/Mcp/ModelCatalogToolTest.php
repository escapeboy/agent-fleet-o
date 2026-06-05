<?php

namespace Tests\Feature\Mcp;

use App\Infrastructure\AI\Services\ManagedPricingStore;
use App\Mcp\Tools\Shared\ModelCatalogTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Request;
use Tests\TestCase;

class ModelCatalogToolTest extends TestCase
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
    }

    private function invokeTool(array $args): string
    {
        return (string) (new ModelCatalogTool)->handle(new Request($args))->content();
    }

    public function test_disabled_when_flag_off(): void
    {
        Config::set('model_catalog.enabled', false);

        $this->assertStringContainsString('disabled', strtolower($this->invokeTool(['action' => 'list', 'provider' => 'openrouter'])));
    }

    public function test_list_returns_live_catalog(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response(['data' => [['id' => 'google/gemma-4-12b-it', 'name' => 'Gemma 4 12B']]], 200)]);

        $out = json_decode($this->invokeTool(['action' => 'list', 'provider' => 'openrouter']), true);

        $this->assertSame(1, $out['count']);
        $this->assertSame('google/gemma-4-12b-it', $out['models'][0]['id']);
    }

    public function test_list_rejects_non_dynamic_provider(): void
    {
        Config::set('model_catalog.enabled', true);

        $this->assertStringContainsString('dynamic catalog', strtolower($this->invokeTool(['action' => 'list', 'provider' => 'anthropic'])));
    }

    public function test_refresh_syncs_pricing(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response([
            'data' => [['id' => 'google/gemma-4-12b-it', 'name' => 'Gemma 4 12B', 'pricing' => ['prompt' => '0.00000006', 'completion' => '0.00000033']]],
        ], 200)]);

        $out = $this->invokeTool(['action' => 'refresh', 'provider' => 'openrouter']);

        $this->assertStringContainsString('refreshed', strtolower($out));
        $this->assertArrayHasKey('google/gemma-4-12b-it', app(ManagedPricingStore::class)->forProvider('openrouter'));
    }
}
