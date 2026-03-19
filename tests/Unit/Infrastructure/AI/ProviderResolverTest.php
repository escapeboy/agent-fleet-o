<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Services\LocalLlmDiscovery;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProviderResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        // Bust discovery cache so each test starts clean
        Cache::flush();
        // Instantiate directly to bypass any container singleton override
        // (e.g. CloudProviderResolver, which unconditionally strips http_local providers)
        $this->resolver = new ProviderResolver();
    }

    // ── availableProviders ───────────────────────────────────────────────────

    public function test_ollama_models_come_from_live_api_not_hardcoded(): void
    {
        Config::set('local_llm.enabled', true);
        Config::set('local_agents.enabled', false);

        // Use Http::fake() so LocalLlmDiscovery's real HTTP call is intercepted
        Http::fake([
            'localhost:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'llama3.2'],
                    ['name' => 'phi4'],
                    ['name' => 'gemma3:12b'],
                ],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $providers = $this->resolver->availableProviders(null);

        $this->assertArrayHasKey('ollama', $providers);
        $this->assertArrayHasKey('llama3.2', $providers['ollama']['models']);
        $this->assertArrayHasKey('phi4', $providers['ollama']['models']);
        $this->assertArrayHasKey('gemma3:12b', $providers['ollama']['models']);
        // Hardcoded models that are NOT pulled should not be present
        $this->assertArrayNotHasKey('llama3.3', $providers['ollama']['models']);
        $this->assertArrayNotHasKey('codestral', $providers['ollama']['models']);
    }

    public function test_ollama_shows_empty_models_when_endpoint_unreachable(): void
    {
        Config::set('local_llm.enabled', true);
        Config::set('local_agents.enabled', false);

        // Simulate unreachable endpoint — connection failure returns empty
        Http::fake(['*' => Http::response(null, 500)]);

        $providers = $this->resolver->availableProviders(null);

        $this->assertArrayHasKey('ollama', $providers);
        $this->assertEmpty($providers['ollama']['models'],
            'Should return empty model list when endpoint is unreachable — no hardcoded fallback.');
    }

    public function test_ollama_uses_team_credential_base_url(): void
    {
        Config::set('local_llm.enabled', true);
        Config::set('local_agents.enabled', false);

        $team = Team::factory()->create();
        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'ollama',
            'credentials' => ['base_url' => 'http://192.168.1.10:11434', 'api_key' => ''],
            'is_active' => true,
        ]);

        Http::fake([
            '192.168.1.10:11434/api/tags' => Http::response([
                'models' => [['name' => 'mistral'], ['name' => 'qwen2.5']],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $providers = $this->resolver->availableProviders($team);

        $this->assertArrayHasKey('mistral', $providers['ollama']['models']);
        $this->assertArrayHasKey('qwen2.5', $providers['ollama']['models']);
    }

    public function test_config_set_works_for_local_llm_enabled(): void
    {
        Config::set('local_llm.enabled', true);
        $this->assertTrue(config('local_llm.enabled'), 'Config::set should make local_llm.enabled = true');
    }

    public function test_available_providers_with_local_llm_enabled_has_ollama(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Config::set('local_llm.enabled', true);
        Config::set('local_agents.enabled', false);

        $this->assertTrue(config('local_llm.enabled'), 'local_llm.enabled must be true before calling availableProviders');

        $providers = $this->resolver->availableProviders(null);

        $this->assertArrayHasKey('ollama', $providers,
            'Ollama should be in providers when local_llm.enabled=true. Got: ' . implode(', ', array_keys($providers)));
    }

    public function test_ollama_hidden_when_local_llm_disabled(): void
    {
        Config::set('local_llm.enabled', false);

        $providers = $this->resolver->availableProviders(null);

        $this->assertArrayNotHasKey('ollama', $providers);
    }

    public function test_cloud_provider_hidden_without_credentials(): void
    {
        Config::set('local_llm.enabled', false);
        Config::set('local_agents.enabled', false);
        Config::set('services.platform_api_keys.anthropic', null);
        Config::set('services.platform_api_keys.openai', null);

        $team = Team::factory()->create();
        // No BYOK credentials

        $providers = $this->resolver->availableProviders($team);

        $this->assertArrayNotHasKey('anthropic', $providers);
        $this->assertArrayNotHasKey('openai', $providers);
    }

    public function test_cloud_provider_visible_with_platform_api_key(): void
    {
        Config::set('local_llm.enabled', false);
        Config::set('local_agents.enabled', false);
        Config::set('services.platform_api_keys.anthropic', 'sk-test-key');

        $providers = $this->resolver->availableProviders(null);

        $this->assertArrayHasKey('anthropic', $providers);
        $this->assertNotEmpty($providers['anthropic']['models']);
    }

    public function test_cloud_provider_visible_with_team_byok_key(): void
    {
        Config::set('local_llm.enabled', false);
        Config::set('local_agents.enabled', false);
        Config::set('services.platform_api_keys.openai', null);

        $team = Team::factory()->create();
        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-team-key'],
            'is_active' => true,
        ]);

        $providers = $this->resolver->availableProviders($team);

        $this->assertArrayHasKey('openai', $providers);
    }

    // ── modelsForProvider ────────────────────────────────────────────────────

    public function test_models_for_provider_returns_dynamic_models_for_ollama(): void
    {
        $team = Team::factory()->create();
        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'ollama',
            'credentials' => ['base_url' => 'http://localhost:11434', 'api_key' => ''],
            'is_active' => true,
        ]);

        Http::fake([
            'localhost:11434/api/tags' => Http::response([
                'models' => [['name' => 'deepseek-r1'], ['name' => 'llama3.2']],
            ], 200),
        ]);

        $models = $this->resolver->modelsForProvider('ollama', $team);

        $this->assertArrayHasKey('deepseek-r1', $models);
        $this->assertArrayHasKey('llama3.2', $models);
        // Hardcoded models from old static list must NOT appear
        $this->assertArrayNotHasKey('llama3.3', $models);
        $this->assertArrayNotHasKey('codestral', $models);
    }

    public function test_models_for_provider_returns_static_models_for_cloud_providers(): void
    {
        $models = $this->resolver->modelsForProvider('anthropic', null);

        $this->assertArrayHasKey('claude-sonnet-4-5', $models);
        $this->assertArrayHasKey('claude-haiku-4-5', $models);
    }

    public function test_model_label_matches_model_id_for_dynamically_discovered_ollama_models(): void
    {
        Config::set('local_llm.enabled', true);
        Config::set('local_agents.enabled', false);

        Http::fake([
            'localhost:11434/api/tags' => Http::response([
                'models' => [['name' => 'my-custom-model:latest']],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $providers = $this->resolver->availableProviders(null);

        $model = $providers['ollama']['models']['my-custom-model:latest'] ?? null;
        $this->assertNotNull($model);
        $this->assertSame('my-custom-model:latest', $model['label']);
        $this->assertSame(0, $model['input_cost']);
        $this->assertSame(0, $model['output_cost']);
    }
}
