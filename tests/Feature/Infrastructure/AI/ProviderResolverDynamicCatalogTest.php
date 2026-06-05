<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderResolverDynamicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private ProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->resolver = new ProviderResolver;

        // Keep OpenRouter in availableProviders() via a platform key.
        Config::set('services.platform_api_keys.openrouter', 'test-key');
        Config::set('llm_providers.openrouter.dynamic_catalog', true);
        Config::set('llm_providers.openrouter.models_endpoint', 'https://openrouter.test/api/v1/models');
        Config::set('llm_providers.openrouter.models_endpoint_auth', 'none');
        Config::set('llm_providers.openrouter.catalog_adapter', 'openrouter');
    }

    public function test_flag_off_uses_static_config_catalog(): void
    {
        Config::set('model_catalog.enabled', false);
        Http::fake(['*' => Http::response(['data' => [['id' => 'live/model']]], 200)]);

        $models = $this->resolver->modelsForProvider('openrouter');

        $this->assertArrayHasKey('openrouter/free', $models, 'static catalog preserved when flag off');
        $this->assertArrayNotHasKey('live/model', $models);
        Http::assertNothingSent();
    }

    public function test_flag_on_replaces_with_live_catalog(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response([
            'data' => [
                ['id' => 'google/gemma-4-12b-it', 'name' => 'Gemma 4 12B', 'pricing' => ['prompt' => '0.00000006', 'completion' => '0.00000033']],
                ['id' => 'anthropic/claude-3.5', 'name' => 'Claude 3.5'],
            ],
        ], 200)]);

        $models = $this->resolver->modelsForProvider('openrouter');

        $this->assertArrayHasKey('google/gemma-4-12b-it', $models);
        $this->assertArrayHasKey('anthropic/claude-3.5', $models);
        $this->assertSame('Gemma 4 12B', $models['google/gemma-4-12b-it']['label']);
    }

    public function test_flag_on_but_unreachable_falls_back_to_static(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response(null, 503)]);

        $models = $this->resolver->modelsForProvider('openrouter');

        $this->assertArrayHasKey('openrouter/free', $models, 'falls back to static when endpoint down');
    }

    public function test_available_providers_includes_dynamic_models(): void
    {
        Config::set('model_catalog.enabled', true);
        Http::fake(['*' => Http::response(['data' => [['id' => 'x/live-model', 'name' => 'Live']]], 200)]);

        $providers = $this->resolver->availableProviders();

        $this->assertArrayHasKey('openrouter', $providers);
        $this->assertArrayHasKey('x/live-model', $providers['openrouter']['models']);
    }

    public function test_non_dynamic_provider_untouched(): void
    {
        Config::set('model_catalog.enabled', true);
        Config::set('services.platform_api_keys.anthropic', 'k');
        Http::fake(['*' => Http::response(['data' => [['id' => 'should/not/apply']]], 200)]);

        $models = $this->resolver->modelsForProvider('anthropic');

        $this->assertArrayHasKey('claude-sonnet-4-5', $models);
        $this->assertArrayNotHasKey('should/not/apply', $models);
    }
}
