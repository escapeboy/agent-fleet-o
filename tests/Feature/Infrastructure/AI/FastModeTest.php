<?php

namespace Tests\Feature\Infrastructure\AI;

use App\Domain\Budget\Services\CostCalculator;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Covers the Anthropic Fast Mode per-request beta header injection.
 * We exercise the helpers directly via reflection to avoid standing up
 * the full Prism/middleware stack for pure config routing.
 */
class FastModeTest extends TestCase
{
    private function invokePrivate(object $target, string $method, array $args): mixed
    {
        $ref = new ReflectionMethod($target, $method);

        return $ref->invoke($target, ...$args);
    }

    private function makeGateway(): PrismAiGateway
    {
        $cost = $this->createMock(CostCalculator::class);

        return new PrismAiGateway(costCalculator: $cost);
    }

    private function makeRequest(array $overrides = []): AiRequestDTO
    {
        $defaults = [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'systemPrompt' => 'sys',
            'userPrompt' => 'usr',
            'purpose' => null,
            'fastMode' => false,
        ];
        $args = array_merge($defaults, $overrides);

        return new AiRequestDTO(
            provider: $args['provider'],
            model: $args['model'],
            systemPrompt: $args['systemPrompt'],
            userPrompt: $args['userPrompt'],
            purpose: $args['purpose'],
            fastMode: $args['fastMode'],
        );
    }

    public function test_fast_mode_is_off_when_globally_disabled(): void
    {
        config(['ai_routing.fast_mode.enabled' => false]);

        $gateway = $this->makeGateway();
        $request = $this->makeRequest(['fastMode' => true]);

        $this->assertFalse($this->invokePrivate($gateway, 'isEffectiveFastMode', [$request]));
    }

    public function test_explicit_fast_mode_flag_enables_when_globally_on(): void
    {
        config(['ai_routing.fast_mode.enabled' => true]);

        $gateway = $this->makeGateway();
        $request = $this->makeRequest(['fastMode' => true]);

        $this->assertTrue($this->invokePrivate($gateway, 'isEffectiveFastMode', [$request]));
    }

    public function test_auto_enables_for_matching_purpose_prefix(): void
    {
        config([
            'ai_routing.fast_mode.enabled' => true,
            'ai_routing.fast_mode.auto_enable_purpose_prefixes' => ['signal.', 'digest.'],
        ]);

        $gateway = $this->makeGateway();

        $signal = $this->makeRequest(['purpose' => 'signal.ingest_classify']);
        $digest = $this->makeRequest(['purpose' => 'digest.weekly_summary']);
        $other = $this->makeRequest(['purpose' => 'agent.execute_with_tools']);

        $this->assertTrue($this->invokePrivate($gateway, 'isEffectiveFastMode', [$signal]));
        $this->assertTrue($this->invokePrivate($gateway, 'isEffectiveFastMode', [$digest]));
        $this->assertFalse($this->invokePrivate($gateway, 'isEffectiveFastMode', [$other]));
    }

    public function test_per_request_config_injects_beta_identifier_for_anthropic_fast_path(): void
    {
        config([
            'ai_routing.fast_mode.enabled' => true,
            'ai_routing.fast_mode.beta_identifier' => 'fast-test-id',
        ]);

        $gateway = $this->makeGateway();
        $request = $this->makeRequest(['fastMode' => true]);

        $config = $this->invokePrivate($gateway, 'getPerRequestProviderConfig', [$request]);

        $this->assertSame('fast-test-id', $config['anthropic_beta'] ?? null);
    }

    public function test_per_request_config_is_empty_for_non_anthropic_provider(): void
    {
        config([
            'ai_routing.fast_mode.enabled' => true,
        ]);

        $gateway = $this->makeGateway();
        $request = $this->makeRequest(['provider' => 'openai', 'fastMode' => true]);

        $config = $this->invokePrivate($gateway, 'getPerRequestProviderConfig', [$request]);

        $this->assertArrayNotHasKey('anthropic_beta', $config);
    }

    public function test_crlf_injection_in_beta_identifier_is_stripped(): void
    {
        config([
            'ai_routing.fast_mode.enabled' => true,
            // Misconfigured env value with injected CRLF — would allow HTTP
            // response splitting if forwarded unsanitised.
            'ai_routing.fast_mode.beta_identifier' => "fast-safe\r\nX-Injected: evil",
        ]);

        $gateway = $this->makeGateway();
        $request = $this->makeRequest(['fastMode' => true]);

        $config = $this->invokePrivate($gateway, 'getPerRequestProviderConfig', [$request]);

        $this->assertSame('fast-safeX-Injected: evil', $config['anthropic_beta']);
        $this->assertStringNotContainsString("\r", $config['anthropic_beta']);
        $this->assertStringNotContainsString("\n", $config['anthropic_beta']);
    }

    public function test_non_matching_purpose_does_not_auto_enable(): void
    {
        config([
            'ai_routing.fast_mode.enabled' => true,
            'ai_routing.fast_mode.auto_enable_purpose_prefixes' => ['signal.'],
        ]);

        $gateway = $this->makeGateway();
        $request = $this->makeRequest(['purpose' => 'agent.execute']);

        $this->assertFalse($this->invokePrivate($gateway, 'isEffectiveFastMode', [$request]));
    }
}
