<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\NoopTracer;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\Sampler\TraceIdRatioBasedSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider as OtelTracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use Throwable;

/**
 * Lazy wrapper around the OpenTelemetry SDK TracerProvider.
 *
 * - When telemetry.enabled=false → returns a NoopTracer (zero overhead).
 * - When enabled but exporter initialization fails → logs and falls back to noop.
 * - Shutdown method should be called on terminate to flush pending spans.
 *
 * IMPORTANT: Span attributes are exported verbatim. If an attribute value may
 * contain user input, credentials, tokens, or other secrets, pass it through
 * `app(AttributeRedactor::class)->sanitize($key, $value)` before calling
 * `setAttribute()`. The redactor strips values whose keys match the
 * `telemetry.redacted_attributes` list (authorization, cookie, api_key, etc.).
 */
class TracerProvider
{
    private ?OtelTracerProvider $sdkProvider = null;

    private ?TracerInterface $cachedTracer = null;

    private bool $initialized = false;

    public function tracer(string $name = 'fleetq'): TracerInterface
    {
        if ($this->cachedTracer !== null) {
            return $this->cachedTracer;
        }

        if (! config('telemetry.enabled')) {
            return $this->cachedTracer = new NoopTracer;
        }

        try {
            $this->sdkProvider = $this->buildSdkProvider();
            $this->cachedTracer = $this->sdkProvider->getTracer(
                $name,
                (string) config('telemetry.service_version', '1.0.0'),
            );
        } catch (Throwable $e) {
            report($e);
            $this->cachedTracer = new NoopTracer;
        }

        $this->initialized = true;

        return $this->cachedTracer;
    }

    public function shutdown(): void
    {
        if ($this->sdkProvider !== null) {
            try {
                $this->sdkProvider->shutdown();
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function isActive(): bool
    {
        return $this->initialized && ! ($this->cachedTracer instanceof NoopTracer);
    }

    private function buildSdkProvider(): OtelTracerProvider
    {
        $endpoint = rtrim((string) config('telemetry.exporter.endpoint'), '/').'/v1/traces';
        $timeout = (float) config('telemetry.exporter.timeout_seconds', 5.0);
        $compression = (string) config('telemetry.exporter.compression', 'gzip');

        $transport = (new OtlpHttpTransportFactory)->create(
            endpoint: $endpoint,
            contentType: 'application/x-protobuf',
            compression: $compression === 'none' ? null : $compression,
            timeout: $timeout,
        );

        $exporter = new SpanExporter($transport);

        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => (string) config('telemetry.service_name', 'fleetq'),
                ResourceAttributes::SERVICE_VERSION => (string) config('telemetry.service_version', '1.0.0'),
                ResourceAttributes::DEPLOYMENT_ENVIRONMENT => (string) config('telemetry.deployment_environment', 'production'),
            ])),
        );

        $sampleRate = (float) config('telemetry.sample_rate', 1.0);
        $sampler = $sampleRate <= 0
            ? new ParentBased(new AlwaysOffSampler)
            : new ParentBased(new TraceIdRatioBasedSampler($sampleRate));

        return OtelTracerProvider::builder()
            ->addSpanProcessor(new BatchSpanProcessor(
                exporter: $exporter,
                clock: Clock::getDefault(),
            ))
            ->setResource($resource)
            ->setSampler($sampler)
            ->build();
    }
}
