<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Jobs\RunShadowComparisonJob;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Schema\ObjectSchema;
use Tests\TestCase;

class ShadowTrafficDispatchTest extends TestCase
{
    private PrismAiGateway $gateway;

    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(PrismAiGateway::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
        $this->circuitBreaker->method('isAvailable')->willReturn(true);
        $this->gateway->method('complete')->willReturn($this->primaryResponse());

        config([
            'services.anthropic.key' => 'sk-test',
            // shadow target — different from the primary model
            'ai_routing.shadow_traffic.provider' => 'openai',
            'ai_routing.shadow_traffic.model' => 'gpt-4o',
            'ai_routing.shadow_traffic.sample_rate' => 1.0,
            'ai_routing.shadow_traffic.queue' => 'metrics',
        ]);

        Queue::fake();
    }

    private function primaryResponse(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'primary output',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 10),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
        );
    }

    private function gatewayUnderTest(): FallbackAiGateway
    {
        return new FallbackAiGateway($this->gateway, $this->circuitBreaker);
    }

    private function request(array $overrides = []): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: $overrides['systemPrompt'] ?? 'sys',
            userPrompt: $overrides['userPrompt'] ?? 'hello',
            outputSchema: $overrides['outputSchema'] ?? null,
            purpose: $overrides['purpose'] ?? 'unit-test',
            tools: $overrides['tools'] ?? null,
        );
    }

    public function test_no_dispatch_when_disabled(): void
    {
        config(['ai_routing.shadow_traffic.enabled' => false]);

        $response = $this->gatewayUnderTest()->complete($this->request());

        $this->assertSame('primary output', $response->content);
        Queue::assertNotPushed(RunShadowComparisonJob::class);
    }

    public function test_dispatches_when_enabled_and_sampled(): void
    {
        config(['ai_routing.shadow_traffic.enabled' => true]);

        $response = $this->gatewayUnderTest()->complete($this->request());

        // Primary response is unaffected by the shadow dispatch.
        $this->assertSame('primary output', $response->content);
        $this->assertSame('anthropic', $response->provider);

        Queue::assertPushed(RunShadowComparisonJob::class, function (RunShadowComparisonJob $job) {
            return $job->queue === 'metrics';
        });
    }

    public function test_no_dispatch_for_shadow_purpose(): void
    {
        config(['ai_routing.shadow_traffic.enabled' => true]);

        $this->gatewayUnderTest()->complete($this->request(['purpose' => 'unit-test:shadow']));

        Queue::assertNotPushed(RunShadowComparisonJob::class);
    }

    public function test_no_dispatch_for_structured_request(): void
    {
        config(['ai_routing.shadow_traffic.enabled' => true]);

        $this->gatewayUnderTest()->complete($this->request([
            'outputSchema' => new ObjectSchema('out', 'desc', []),
        ]));

        Queue::assertNotPushed(RunShadowComparisonJob::class);
    }

    public function test_no_dispatch_when_sample_rate_zero(): void
    {
        config([
            'ai_routing.shadow_traffic.enabled' => true,
            'ai_routing.shadow_traffic.sample_rate' => 0.0,
        ]);

        $this->gatewayUnderTest()->complete($this->request());

        Queue::assertNotPushed(RunShadowComparisonJob::class);
    }
}
