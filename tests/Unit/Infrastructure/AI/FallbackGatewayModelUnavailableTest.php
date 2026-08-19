<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Exception;
use Tests\TestCase;

class FallbackGatewayModelUnavailableTest extends TestCase
{
    private PrismAiGateway $gateway;

    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createMock(PrismAiGateway::class);
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);

        config(['services.groq.key' => 'gsk-test']);
    }

    private function makeRequest(): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'groq',
            model: 'llama-3.3-70b-versatile',
            systemPrompt: 'test',
            userPrompt: 'test',
        );
    }

    public function test_retired_model_id_does_not_open_the_circuit(): void
    {
        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, []);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);
        $this->circuitBreaker->expects($this->never())->method('recordFailure');

        $this->gateway->method('complete')->willThrowException(new Exception(
            'Groq Error [404]: invalid_request_error - The model `llama-3.3-70b-versatile` does not exist or you do not have access to it.'
        ));

        $this->expectException(Exception::class);
        $fallbackGateway->complete($this->makeRequest());
    }

    public function test_genuine_provider_failure_still_opens_the_circuit(): void
    {
        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, []);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);
        $this->circuitBreaker->expects($this->once())->method('recordFailure')->with('groq');

        $this->gateway->method('complete')->willThrowException(new Exception('Groq Error [503]: upstream unavailable'));

        $this->expectException(Exception::class);
        $fallbackGateway->complete($this->makeRequest());
    }
}
