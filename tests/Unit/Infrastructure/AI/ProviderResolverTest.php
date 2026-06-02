<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\Skill;
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
        $this->resolver = new ProviderResolver;
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
            'Ollama should be in providers when local_llm.enabled=true. Got: '.implode(', ', array_keys($providers)));
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

    // ── resolveWithSource ──────────────────────────────────────────────────

    public function test_resolve_with_source_returns_agent_source(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);

        $result = $this->resolver->resolveWithSource(agent: $agent, team: $team);

        $this->assertSame('openai', $result['provider']);
        $this->assertSame('gpt-4o', $result['model']);
        $this->assertSame('agent', $result['source']);
    }

    public function test_resolve_with_source_falls_back_to_team_default(): void
    {
        $team = Team::factory()->create([
            'settings' => [
                'default_llm_provider' => 'google',
                'default_llm_model' => 'gemini-2.5-flash',
            ],
        ]);
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => '',
            'model' => '',
        ]);

        $result = $this->resolver->resolveWithSource(agent: $agent, team: $team);

        $this->assertSame('google', $result['provider']);
        $this->assertSame('gemini-2.5-flash', $result['model']);
        $this->assertSame('team', $result['source']);
    }

    public function test_resolve_with_source_returns_skill_source(): void
    {
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'configuration' => [
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
            ],
        ]);

        $result = $this->resolver->resolveWithSource(skill: $skill, team: $team);

        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame('claude-haiku-4-5', $result['model']);
        $this->assertSame('skill', $result['source']);
    }

    public function test_resolve_with_source_returns_config_fallback(): void
    {
        Config::set('llm_pricing.default_provider', 'anthropic');
        Config::set('llm_pricing.default_model', 'claude-sonnet-4-5');

        $result = $this->resolver->resolveWithSource();

        $this->assertSame('anthropic', $result['provider']);
        $this->assertSame('claude-sonnet-4-5', $result['model']);
        $this->assertContains($result['source'], ['platform', 'config']);
    }

    // ── model/provider mismatch (cloud-only flip guard) ───────────────────────

    public function test_resolve_swaps_foreign_model_to_provider_default(): void
    {
        // Cloud-only deployment: only OpenAI has a key. An agent is still configured
        // with a claude model on the openai provider (e.g. after a team flip to
        // cloud-only). The foreign model must be swapped to an openai catalog model
        // so the gateway never POSTs "claude-haiku-4-5" to the OpenAI endpoint.
        Config::set('local_llm.enabled', false);
        Config::set('local_agents.enabled', false);
        Config::set('services.platform_api_keys.anthropic', null);
        Config::set('services.platform_api_keys.openai', 'sk-openai-key');

        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'model' => 'claude-haiku-4-5',
        ]);

        $resolved = $this->resolver->resolve(agent: $agent, team: $team);

        $this->assertSame('openai', $resolved['provider']);
        $this->assertArrayHasKey(
            $resolved['model'],
            config('llm_providers.openai.models'),
            'Resolved model must belong to the OpenAI catalog, not a foreign claude name.',
        );
    }

    public function test_resolve_reroutes_anthropic_agent_without_key_to_openai(): void
    {
        // Agents configured anthropic/claude-* but the deployment only has an OpenAI
        // key: enforceAvailability reroutes to openai, then the model guard swaps the
        // claude model for an openai catalog model — a fully valid pair, no breaker hit.
        Config::set('local_llm.enabled', false);
        Config::set('local_agents.enabled', false);
        Config::set('services.platform_api_keys.anthropic', null);
        Config::set('services.platform_api_keys.openai', 'sk-openai-key');

        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);

        $resolved = $this->resolver->resolve(agent: $agent, team: $team);

        $this->assertSame('openai', $resolved['provider']);
        $this->assertArrayHasKey($resolved['model'], config('llm_providers.openai.models'));
    }

    public function test_resolve_keeps_valid_provider_model_pair_untouched(): void
    {
        Config::set('local_llm.enabled', false);
        Config::set('local_agents.enabled', false);
        Config::set('services.platform_api_keys.openai', 'sk-openai-key');

        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ]);

        $resolved = $this->resolver->resolve(agent: $agent, team: $team);

        $this->assertSame('openai', $resolved['provider']);
        $this->assertSame('gpt-4o', $resolved['model']);
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
