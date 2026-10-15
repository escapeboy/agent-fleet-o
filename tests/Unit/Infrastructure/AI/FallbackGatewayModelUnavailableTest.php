<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Gateways\FallbackAiGateway;
use App\Infrastructure\AI\Gateways\PrismAiGateway;
use App\Infrastructure\AI\Services\CircuitBreaker;
use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use Prism\Prism\Exceptions\PrismException;
use Tests\TestCase;

class FallbackGatewayModelUnavailableTest extends TestCase
{
    private PrismAiGateway $gateway;

    private CircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = $this->createStub(PrismAiGateway::class);
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
            'Groq Error [404]: invalid_request_error - The model `llama-3.3-70b-versatile` does not exist or you do not have access to it.',
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

    private function anthropicRequest(): AiRequestDTO
    {
        config(['ai.providers.anthropic.key' => 'sk-ant-test']);

        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'test',
            userPrompt: 'test',
        );
    }

    /** @return array<string, array{int, string, string}> */
    public static function clientErrors(): array
    {
        return [
            '404 unknown model (2026-10-15 incident)' => [404, 'not_found_error', 'model: claude-sonnet-4-5'],
            '400 invalid request' => [400, 'invalid_request_error', 'model: invalid model id'],
        ];
    }

    #[DataProvider('clientErrors')]
    public function test_anthropic_client_error_does_not_open_the_circuit(int $status, string $type, string $message): void
    {
        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, []);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);
        $this->circuitBreaker->expects($this->never())->method('recordFailure');

        $this->gateway->method('complete')->willThrowException(
            PrismException::providerRequestErrorWithDetails('Anthropic', $status, $type, $message),
        );

        $this->expectException(PrismException::class);
        $fallbackGateway->complete($this->anthropicRequest());
    }

    public function test_anthropic_client_error_does_not_open_the_circuit_when_streaming(): void
    {
        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, []);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);
        $this->circuitBreaker->expects($this->never())->method('recordFailure');

        $this->gateway->method('stream')->willThrowException(
            PrismException::providerRequestErrorWithDetails('Anthropic', 404, 'not_found_error', 'model: claude-sonnet-4-5'),
        );

        $this->expectException(PrismException::class);
        $fallbackGateway->stream($this->anthropicRequest());
    }

    /** @return array<string, array{int}> */
    public static function providerFailures(): array
    {
        return [
            '408 timeout' => [408],
            '500 server error' => [500],
            '503 unavailable' => [503],
        ];
    }

    #[DataProvider('providerFailures')]
    public function test_anthropic_timeout_and_server_errors_still_open_the_circuit(int $status): void
    {
        $fallbackGateway = new FallbackAiGateway($this->gateway, $this->circuitBreaker, []);

        $this->circuitBreaker->method('isAvailable')->willReturn(true);
        $this->circuitBreaker->expects($this->once())->method('recordFailure')->with('anthropic');

        $this->gateway->method('complete')->willThrowException(
            PrismException::providerRequestErrorWithDetails('Anthropic', $status, 'api_error', 'upstream failure'),
        );

        $this->expectException(PrismException::class);
        $fallbackGateway->complete($this->anthropicRequest());
    }
}
