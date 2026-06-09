<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class ProviderHasApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private FallbackAiGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FallbackAiGateway(
            $this->createMock(PrismAiGateway::class),
            $this->createMock(CircuitBreaker::class),
        );

        // Clear all cloud-provider keys; individual tests opt back in.
        config([
            'ai.providers.anthropic.key' => null,
            'ai.providers.anthropic.api_key' => null,
            'ai.providers.openai.key' => null,
            'ai.providers.openai.api_key' => null,
            'ai.providers.gemini.key' => null,
            'ai.providers.gemini.api_key' => null,
            'services.anthropic.key' => null,
            'services.anthropic' => null,
            'services.openai.key' => null,
            'services.openai' => null,
            'services.gemini.key' => null,
            'services.gemini' => null,
            'services.google.key' => null,
            'services.google' => null,
            'services.sub_program_api_keys.anthropic' => null,
            'services.sub_program_api_keys.openai' => null,
        ]);
    }

    private function invoke(string $provider, ?string $teamId = null): bool
    {
        $reflection = new ReflectionClass($this->gateway);
        $method = $reflection->getMethod('providerHasApiKey');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->gateway, $provider, $teamId);
    }

    public function test_returns_true_when_provider_has_key_in_ai_providers_config(): void
    {
        config(['ai.providers.openai.key' => 'sk-svcacct-test-167-chars']);

        $this->assertTrue($this->invoke('openai'));
    }

    public function test_returns_true_for_anthropic_with_key_field(): void
    {
        config(['ai.providers.anthropic.key' => 'sk-ant-test']);

        $this->assertTrue($this->invoke('anthropic'));
    }

    public function test_returns_true_when_provider_has_api_key_field_for_backcompat(): void
    {
        config(['ai.providers.openai.api_key' => 'sk-test-backcompat']);

        $this->assertTrue($this->invoke('openai'));
    }

    public function test_returns_true_when_only_services_config_is_populated(): void
    {
        config(['services.openai.key' => 'sk-services-test']);

        $this->assertTrue($this->invoke('openai'));
    }

    public function test_returns_true_when_only_sub_program_key_is_configured(): void
    {
        // Sub-program teams (finance) use a dedicated key; the platform key is
        // intentionally empty. The team-blind gate must still admit the provider.
        config(['services.sub_program_api_keys.anthropic' => 'sk-ant-subprogram-test']);

        $this->assertTrue($this->invoke('anthropic'));
    }

    public function test_returns_false_when_no_key_or_api_key_configured(): void
    {
        $this->assertFalse($this->invoke('openai'));
        $this->assertFalse($this->invoke('anthropic'));
        $this->assertFalse($this->invoke('gemini'));
    }

    public function test_returns_false_when_key_is_empty_string(): void
    {
        config([
            'ai.providers.openai.key' => '',
            'ai.providers.openai.api_key' => '',
            'services.openai.key' => '',
        ]);

        $this->assertFalse($this->invoke('openai'));
    }

    public function test_local_and_bridge_providers_always_return_true(): void
    {
        // Local agents (claude-code, codex) and bridge providers don't need API keys.
        $this->assertTrue($this->invoke('claude-code'));
        $this->assertTrue($this->invoke('codex'));
    }

    public function test_returns_true_when_team_has_active_byok_credential_without_platform_key(): void
    {
        // Regression: a BYOK-only provider (openrouter) the platform has no key
        // for must still pass the gate when the team has an active credential —
        // otherwise the fallback chain exhausts to "No available providers".
        $team = Team::factory()->create();
        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'openrouter',
            'credentials' => ['api_key' => 'sk-or-team-key'],
            'is_active' => true,
        ]);

        $this->assertFalse($this->invoke('openrouter'), 'team-blind check must still fail without a team id');
        $this->assertTrue($this->invoke('openrouter', $team->id));
    }

    public function test_returns_false_when_team_credential_is_inactive(): void
    {
        $team = Team::factory()->create();
        TeamProviderCredential::create([
            'team_id' => $team->id,
            'provider' => 'openrouter',
            'credentials' => ['api_key' => 'sk-or-team-key'],
            'is_active' => false,
        ]);

        $this->assertFalse($this->invoke('openrouter', $team->id));
    }

    /**
     * Acceptance-test from the upstream bug report:
     * for each cloud provider declared in config/ai.php with a populated
     * `key`, providerHasApiKey() must return true.
     */
    public function test_matches_every_configured_ai_providers_entry_with_key_set(): void
    {
        $configured = [
            'anthropic' => 'sk-ant-test',
            'openai' => 'sk-openai-test',
            'gemini' => 'gemini-test',
        ];

        foreach ($configured as $provider => $key) {
            config(["ai.providers.{$provider}.key" => $key]);
            $this->assertTrue(
                $this->invoke($provider),
                "providerHasApiKey({$provider}) should return true when ai.providers.{$provider}.key is set",
            );
        }
    }
}
