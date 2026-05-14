<?php

namespace Tests\Unit\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Middleware\PhoenixExportMiddleware;
use App\Infrastructure\AI\Services\OpenInferenceAttributes;
use App\Infrastructure\AI\Services\PhoenixTraceContext;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class PhoenixExportMiddlewareTest extends TestCase
{
    private PhoenixExportMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new PhoenixExportMiddleware(
            new OpenInferenceAttributes,
            new PhoenixTraceContext,
        );
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

    public function test_dispatches_export_job_with_expected_args(): void
    {
        config([
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
            'llmops.phoenix.api_key' => 'secret',
            'llmops.phoenix.project' => 'fleetq-test',
            'llmops.phoenix.sample_rate' => 1.0,
        ]);
        Bus::fake();

        $this->middleware->handle($this->request(), fn () => $this->response());

        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) {
            $reflection = new \ReflectionClass($job);
            $get = fn (string $name) => $reflection->getProperty($name)->getValue($job);

            return $get('endpoint') === 'http://phoenix:6006'
                && $get('apiKey') === 'secret'
                && $get('project') === 'fleetq-test'
                && $get('spanName') === 'unit-test'
                && is_string($get('traceId'))
                && strlen((string) $get('traceId')) === 32
                && strlen((string) $get('spanId')) === 16
                && $get('parentSpanId') === null
                && is_array($get('attributes'))
                && $get('attributes')['llm.model_name'] === 'claude-haiku-4-5'
                && $get('endNanos') > $get('startNanos')
                && ($get('endNanos') - $get('startNanos')) >= 100 * 1_000_000;
        });
    }

    public function test_swallows_attribute_building_errors(): void
    {
        config(['llmops.phoenix.enabled' => true, 'llmops.phoenix.endpoint' => 'http://phoenix:6006']);
        Bus::fake();

        $brokenAttrs = Mockery::mock(OpenInferenceAttributes::class);
        $brokenAttrs->shouldReceive('forLlmCall')->andThrow(new \RuntimeException('boom'));

        $middleware = new PhoenixExportMiddleware($brokenAttrs, new PhoenixTraceContext);
        $response = $this->response();

        $result = $middleware->handle($this->request(), fn () => $response);

        $this->assertSame($response, $result);
        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_skips_dispatch_when_sample_rate_is_zero(): void
    {
        config([
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
            'llmops.phoenix.sample_rate' => 0.0,
        ]);
        Bus::fake();

        $this->middleware->handle($this->request(), fn () => $this->response());

        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_force_sample_when_parent_context_is_set(): void
    {
        config([
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
            'llmops.phoenix.sample_rate' => 0.0, // would otherwise drop
        ]);
        Bus::fake();

        $traceCtx = new PhoenixTraceContext;
        // Force a parent context via push (without the dispatch side-effect we
        // only care about the IDs here).
        $traceCtx->push('test.root', ['metadata.test' => 'parent']);

        $middleware = new PhoenixExportMiddleware(new OpenInferenceAttributes, $traceCtx);
        $middleware->handle($this->request(), fn () => $this->response());

        $traceCtx->reset();

        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) {
            $reflection = new \ReflectionClass($job);
            $parentSpan = $reflection->getProperty('parentSpanId')->getValue($job);

            return is_string($parentSpan) && strlen($parentSpan) === 16;
        });
    }

    public function test_dto_parent_ids_take_precedence_over_trace_context(): void
    {
        config([
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
        ]);
        Bus::fake();

        $traceCtx = new PhoenixTraceContext;
        $traceCtx->push('ignored.root', []);

        $middleware = new PhoenixExportMiddleware(new OpenInferenceAttributes, $traceCtx);

        $request = new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            systemPrompt: 's',
            userPrompt: 'u',
            purpose: 'with-explicit-parent',
            parentTraceId: str_repeat('a', 32),
            parentSpanId: str_repeat('b', 16),
        );

        $middleware->handle($request, fn () => $this->response());
        $traceCtx->reset();

        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) {
            $reflection = new \ReflectionClass($job);
            $get = fn (string $name) => $reflection->getProperty($name)->getValue($job);

            return $get('traceId') === str_repeat('a', 32)
                && $get('parentSpanId') === str_repeat('b', 16);
        });
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
