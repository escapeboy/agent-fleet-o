<?php

namespace Tests\Unit\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Middleware\PhoenixExportMiddleware;
use App\Infrastructure\AI\Services\OpenInferenceAttributes;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PhoenixExportMiddlewareTest extends TestCase
{
    private PhoenixExportMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new PhoenixExportMiddleware(new OpenInferenceAttributes);
    }

    public function test_does_not_dispatch_when_endpoint_empty(): void
    {
        config(['llmops.phoenix.enabled' => false, 'llmops.phoenix.endpoint' => '']);
        Bus::fake();

        $result = $this->middleware->handle($this->request(), fn () => $this->response());

        $this->assertSame('out', $result->content);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_does_not_dispatch_when_response_is_cached(): void
    {
        config(['llmops.phoenix.enabled' => true, 'llmops.phoenix.endpoint' => 'http://phoenix:6006']);
        Bus::fake();

        $cached = new AiResponseDTO(
            content: 'out',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 1,
            cached: true,
        );

        $this->middleware->handle($this->request(), fn () => $cached);

        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_dispatches_export_job_when_enabled_and_fresh(): void
    {
        config([
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
            'llmops.phoenix.api_key' => 'secret',
        ]);
        Bus::fake();

        $this->middleware->handle($this->request(), fn () => $this->response());

        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) {
            // Inspect via reflection — the constructor args are readonly private.
            $reflection = new \ReflectionClass($job);
            $endpoint = $reflection->getProperty('endpoint');
            $apiKey = $reflection->getProperty('apiKey');
            $payload = $reflection->getProperty('payload');

            return $endpoint->getValue($job) === 'http://phoenix:6006'
                && $apiKey->getValue($job) === 'secret'
                && isset($payload->getValue($job)['resourceSpans']);
        });
    }

    public function test_swallows_span_building_errors(): void
    {
        // Verify the try/catch by injecting a mock attribute builder that throws.
        // The middleware must still return the response and not dispatch the job.
        config(['llmops.phoenix.enabled' => true, 'llmops.phoenix.endpoint' => 'http://phoenix:6006']);
        Bus::fake();

        $brokenAttrs = Mockery::mock(OpenInferenceAttributes::class);
        $brokenAttrs->shouldReceive('toOtlpAttributes')->andThrow(new \RuntimeException('boom'));

        $middleware = new PhoenixExportMiddleware($brokenAttrs);
        $response = $this->response();

        $result = $middleware->handle($this->request(), fn () => $response);

        $this->assertSame($response, $result);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    private function request(): AiRequestDTO
    {
        return new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            systemPrompt: 'sys',
            userPrompt: 'usr',
            purpose: 'unit-test',
        );
    }

    private function response(): AiResponseDTO
    {
        return new AiResponseDTO(
            content: 'out',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 100,
        );
    }
}
