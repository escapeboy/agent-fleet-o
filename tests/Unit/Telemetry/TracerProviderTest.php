<?php

declare(strict_types=1);

namespace Tests\Unit\Telemetry;

use App\Infrastructure\Telemetry\TracerProvider;
use OpenTelemetry\API\Trace\NoopTracer;
use Tests\TestCase;

class TracerProviderTest extends TestCase
{
    public function test_returns_noop_tracer_when_disabled(): void
    {
        config(['telemetry.enabled' => false]);

        $provider = new TracerProvider;

        $this->assertInstanceOf(NoopTracer::class, $provider->tracer());
        $this->assertFalse($provider->isActive());
    }

    public function test_noop_span_operations_are_silent(): void
    {
        config(['telemetry.enabled' => false]);

        $provider = new TracerProvider;
        $tracer = $provider->tracer();

        $span = $tracer->spanBuilder('test.span')
            ->setAttribute('key', 'value')
            ->startSpan();

        $scope = $span->activate();
        $span->setAttribute('another', 123);
        $span->end();
        $scope->detach();

        $this->addToAssertionCount(1);
    }

    public function test_shutdown_is_safe_when_disabled(): void
    {
        config(['telemetry.enabled' => false]);

        $provider = new TracerProvider;
        $provider->tracer();
        $provider->shutdown();

        $this->addToAssertionCount(1);
    }

    public function test_falls_back_to_noop_on_invalid_endpoint_config(): void
    {
        config([
            'telemetry.enabled' => true,
            'telemetry.exporter.endpoint' => '',
            'telemetry.exporter.protocol' => 'http/protobuf',
            'telemetry.exporter.timeout_seconds' => 1,
            'telemetry.exporter.compression' => 'none',
            'telemetry.sample_rate' => 1.0,
            'telemetry.service_name' => 'fleetq-test',
            'telemetry.service_version' => '0.0.0',
            'telemetry.deployment_environment' => 'testing',
        ]);

        $provider = new TracerProvider;
        $tracer = $provider->tracer();

        // Empty/invalid endpoint falls back to NoopTracer without throwing.
        $this->assertInstanceOf(NoopTracer::class, $tracer);
    }

    public function test_tracer_is_cached_across_calls(): void
    {
        config(['telemetry.enabled' => false]);

        $provider = new TracerProvider;
        $t1 = $provider->tracer();
        $t2 = $provider->tracer();

        $this->assertSame($t1, $t2);
    }

    public function test_singleton_binding_shares_instance(): void
    {
        $a = app(TracerProvider::class);
        $b = app(TracerProvider::class);

        $this->assertSame($a, $b);
    }
}
