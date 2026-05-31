<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers ProviderResolver::resolveInternal — the BYOK-aware resolver used by
 * internal utility LLM calls (memory, summarisation, scout, judge, planning
 * enrichment) that previously hard-coded anthropic/claude-haiku-4-5 and 401'd
 * on teams without an Anthropic key (prod incident 2026-05-30, team PriceX).
 */
class ProviderResolverInternalTest extends TestCase
{
    use RefreshDatabase;

    private function makeResolver(): ProviderResolver
    {
        return new ProviderResolver;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // No shared platform keys — availability is driven purely by team BYOK,
        // mirroring a cloud team that brings its own keys.
        config([
            'services.platform_api_keys.anthropic' => null,
            'services.platform_api_keys.openai' => null,
            'services.platform_api_keys.google' => null,
            'services.platform_api_keys.openrouter' => null,
        ]);
    }

    public function test_no_anthropic_key_resolves_to_the_byok_provider_not_anthropic(): void
    {
        $team = Team::factory()->create(['settings' => []]);

        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'is_active' => true,
        ]);

        $resolved = $this->makeResolver()->resolveInternal($team, 'cheap');

        $this->assertSame('openai', $resolved['provider']);
        $this->assertSame('gpt-4o-mini', $resolved['model']);
        $this->assertNotSame('anthropic', $resolved['provider']);
    }

    public function test_team_default_provider_wins_when_team_holds_a_key_for_it(): void
    {
        $team = Team::factory()->create([
            'settings' => ['default_llm_provider' => 'google', 'default_llm_model' => 'gemini-2.5-pro'],
        ]);

        // Team holds keys for both google and openai; the configured default (google) should win.
        foreach (['google', 'openai'] as $provider) {
            TeamProviderCredential::create([
                'team_id' => $team->id,
                'provider' => $provider,
                'credentials' => ['api_key' => 'k'],
                'is_active' => true,
            ]);
        }

        $resolved = $this->makeResolver()->resolveInternal($team, 'cheap');

        // The cheap-tier model for the team's default provider, not its chat default model.
        $this->assertSame('google', $resolved['provider']);
        $this->assertSame('gemini-2.5-flash', $resolved['model']);
    }

    public function test_expensive_tier_returns_the_expensive_model_for_the_byok_provider(): void
    {
        $team = Team::factory()->create(['settings' => []]);

        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'google',
            'credentials' => ['api_key' => 'k'],
            'is_active' => true,
        ]);

        $resolved = $this->makeResolver()->resolveInternal($team, 'expensive');

        $this->assertSame('google', $resolved['provider']);
        $this->assertSame('gemini-2.5-pro', $resolved['model']);
    }

    public function test_platform_anthropic_key_is_used_when_present(): void
    {
        config(['services.platform_api_keys.anthropic' => 'platform-key']);

        $team = Team::factory()->create(['settings' => []]);

        $resolved = $this->makeResolver()->resolveInternal($team, 'cheap');

        $this->assertSame('anthropic', $resolved['provider']);
        $this->assertSame('claude-haiku-4-5', $resolved['model']);
    }

    public function test_team_default_ignored_when_no_key_for_it_falls_through_to_available_byok(): void
    {
        // Team default points at anthropic (no key); only openai key exists.
        $team = Team::factory()->create([
            'settings' => ['default_llm_provider' => 'anthropic', 'default_llm_model' => 'claude-sonnet-4-5'],
        ]);

        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'k'],
            'is_active' => true,
        ]);

        $resolved = $this->makeResolver()->resolveInternal($team, 'cheap');

        $this->assertSame('openai', $resolved['provider']);
        $this->assertSame('gpt-4o-mini', $resolved['model']);
    }
}
