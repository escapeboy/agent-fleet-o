<?php

namespace Tests\Unit\Infrastructure\AI\Services;

use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Services\PhoenixTraceContext;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PhoenixTraceContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'llmops.phoenix.enabled' => true,
            'llmops.phoenix.endpoint' => 'http://phoenix:6006',
            'llmops.phoenix.sample_rate' => 1.0,
        ]);
        Bus::fake();
    }

    public function test_current_ids_are_null_outside_with_root(): void
    {
        $ctx = new PhoenixTraceContext;

        $this->assertNull($ctx->currentTraceId());
        $this->assertNull($ctx->currentSpanId());
        $this->assertFalse($ctx->isActive());
    }

    public function test_inside_with_root_ids_are_hex(): void
    {
        $ctx = new PhoenixTraceContext;

        $ctx->withRoot('test.root', [], function () use ($ctx) {
            $this->assertNotNull($ctx->currentTraceId());
            $this->assertNotNull($ctx->currentSpanId());
            $this->assertSame(32, strlen((string) $ctx->currentTraceId()));
            $this->assertSame(16, strlen((string) $ctx->currentSpanId()));
            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', (string) $ctx->currentTraceId());
            $this->assertTrue($ctx->isActive());
        });

        $this->assertNull($ctx->currentTraceId());
    }

    public function test_nested_with_root_inherits_trace_id_new_span_id(): void
    {
        $ctx = new PhoenixTraceContext;

        $outerTrace = null;
        $outerSpan = null;
        $innerTrace = null;
        $innerSpan = null;

        $ctx->withRoot('outer', [], function () use ($ctx, &$outerTrace, &$outerSpan, &$innerTrace, &$innerSpan) {
            $outerTrace = $ctx->currentTraceId();
            $outerSpan = $ctx->currentSpanId();

            $ctx->withRoot('inner', [], function () use ($ctx, &$innerTrace, &$innerSpan) {
                $innerTrace = $ctx->currentTraceId();
                $innerSpan = $ctx->currentSpanId();
            });
        });

        $this->assertSame($outerTrace, $innerTrace, 'nested root must inherit traceId');
        $this->assertNotSame($outerSpan, $innerSpan, 'nested root must get its own spanId');
    }

    public function test_stack_drains_on_exception(): void
    {
        $ctx = new PhoenixTraceContext;

        try {
            $ctx->withRoot('boomy', [], function () use ($ctx) {
                $this->assertTrue($ctx->isActive());
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse($ctx->isActive(), 'finally must pop the frame even on exception');
    }

    public function test_with_root_dispatches_root_span_job(): void
    {
        $ctx = new PhoenixTraceContext;

        $ctx->withRoot('agent.execute', ['metadata.agent_id' => 'agent-uuid'], fn () => 42);

        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) {
            $reflection = new \ReflectionClass($job);
            $get = fn (string $name) => $reflection->getProperty($name)->getValue($job);

            return $get('spanName') === 'agent.execute'
                && $get('attributes')['metadata.agent_id'] === 'agent-uuid'
                && $get('attributes')['openinference.span.kind'] === 'AGENT';
        });
    }

    public function test_no_dispatch_when_sample_rate_is_zero(): void
    {
        config(['llmops.phoenix.sample_rate' => 0.0]);

        $ctx = new PhoenixTraceContext;

        $ctx->withRoot('rolled-out.root', [], fn () => null);

        Bus::assertNotDispatched(ExportToPhoenixJob::class);
    }

    public function test_push_pop_manual_pair(): void
    {
        $ctx = new PhoenixTraceContext;

        $ctx->push('manual.root', ['metadata.x' => 'y']);
        $trace = $ctx->currentTraceId();
        $span = $ctx->currentSpanId();
        $this->assertNotNull($trace);
        $this->assertNotNull($span);

        $ctx->pop();
        $this->assertNull($ctx->currentTraceId());

        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) use ($trace, $span) {
            $reflection = new \ReflectionClass($job);

            return $reflection->getProperty('traceId')->getValue($job) === $trace
                && $reflection->getProperty('spanId')->getValue($job) === $span;
        });
    }

    public function test_span_kind_inferred_from_name(): void
    {
        $ctx = new PhoenixTraceContext;

        $ctx->withRoot('agent.execute', [], fn () => null);
        $ctx->withRoot('crew.execute', [], fn () => null);
        $ctx->withRoot('playbook.step', [], fn () => null);

        Bus::assertDispatched(ExportToPhoenixJob::class, 3);

        $byName = [];
        Bus::assertDispatched(ExportToPhoenixJob::class, function (ExportToPhoenixJob $job) use (&$byName) {
            $reflection = new \ReflectionClass($job);
            $byName[$reflection->getProperty('spanName')->getValue($job)]
                = $reflection->getProperty('attributes')->getValue($job)['openinference.span.kind'];

            return true;
        });

        $this->assertSame('AGENT', $byName['agent.execute']);
        $this->assertSame('CHAIN', $byName['crew.execute']);
        $this->assertSame('CHAIN', $byName['playbook.step']);
    }
}
