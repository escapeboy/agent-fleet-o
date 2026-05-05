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

    /**
     * Optional per-team overrides. When set, `buildSdkProvider()` uses these
     * values instead of the global `config('telemetry.*')`. Consumed by
     * `TenantTracerProviderFactory` for cloud multi-tenant routing.
     *
     * Supported keys: enabled (bool), endpoint, headers, sample_rate,
     * service_name, service_version, deployment_environment.
     *
     * @var array<string, mixed>|null
     */
    private ?array $overrides = null;

    /**
     * Return a fresh TracerProvider instance whose `buildSdkProvider()` uses
     * the given overrides instead of reading global config. Used by
     * TenantTracerProviderFactory to build per-team providers without leaking
     * state between tenants.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $clone = new self;
        $clone->overrides = $overrides;

        return $clone;
    }

    public function tracer(string $name = 'fleetq'): TracerInterface
    {
        if ($this->cachedTracer !== null) {
            return $this->cachedTracer;
        }

        if (! $this->configValue('enabled', (bool) config('telemetry.enabled'))) {
            return $this->cachedTracer = $this->makeNoopTracer();
        }

        try {
            $this->sdkProvider = $this->buildSdkProvider();
            $this->cachedTracer = $this->sdkProvider->getTracer(
                $name,
                (string) $this->configValue('service_version', (string) config('telemetry.service_version', '1.0.0')),
            );
        } catch (Throwable $e) {
            report($e);
            $this->cachedTracer = $this->makeNoopTracer();
        }

        $this->initialized = true;

        return $this->cachedTracer;
    }

    /**
     * Read a config value, preferring per-team override when set. Accepts both
     * flat keys (`enabled`, `endpoint`, `headers`, `sample_rate`) and namespaced
     * fallbacks to `telemetry.*`.
     */
    private function configValue(string $key, mixed $default): mixed
    {
        if ($this->overrides !== null && array_key_exists($key, $this->overrides)) {
            return $this->overrides[$key];
        }

        return $default;
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
        if (! $this->initialized) {
            return false;
        }

        return ! ($this->cachedTracer instanceof NoopTracer)
            && ! ($this->cachedTracer instanceof FallbackNoopTracer);
    }

    /**
     * Return a NoopTracer when telemetry is disabled or OTel isn't installed.
     *
     * Prod's composer install runs against the PARENT composer.json (not base),
     * so the open-telemetry/* packages listed only in base/composer.json are
     * sometimes missing from the shared vendor volume. When that happens, we
     * substitute a minimal in-codebase implementation of TracerInterface so
     * AI code paths don't blow up with "Class NoopTracer not found".
     */
    private function makeNoopTracer(): TracerInterface
    {
        if (class_exists(NoopTracer::class)) {
            return new NoopTracer;
        }

        return new FallbackNoopTracer;
    }

    /**
     * Parse the OTel SDK-standard `key=value,key2=value2` header format.
     * Silently drops malformed entries — a bad header line shouldn't crash
     * tracing, just reduce auth headers sent.
     *
     * @return array<string, string>
     */
    private function parseHeaders(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $result = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key !== '') {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function buildSdkProvider(): OtelTracerProvider
    {
        $endpoint = rtrim((string) $this->configValue('endpoint', (string) config('telemetry.exporter.endpoint')), '/').'/v1/traces';
        $timeout = (float) $this->configValue('timeout_seconds', (float) config('telemetry.exporter.timeout_seconds', 5.0));
        $compression = (string) $this->configValue('compression', (string) config('telemetry.exporter.compression', 'gzip'));

        $rawHeaders = $this->configValue('headers', config('telemetry.exporter.headers', ''));
        $headers = is_array($rawHeaders) ? $rawHeaders : $this->parseHeaders((string) $rawHeaders);

        $transport = (new OtlpHttpTransportFactory)->create(
            endpoint: $endpoint,
            contentType: 'application/x-protobuf',
            headers: $headers,
            compression: $compression === 'none' ? null : $compression,
            timeout: $timeout,
        );

        $exporter = new SpanExporter($transport);

        $resource = ResourceInfoFactory::emptyResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => (string) $this->configValue('service_name', (string) config('telemetry.service_name', 'fleetq')),
                ResourceAttributes::SERVICE_VERSION => (string) $this->configValue('service_version', (string) config('telemetry.service_version', '1.0.0')),
                ResourceAttributes::DEPLOYMENT_ENVIRONMENT => (string) $this->configValue('deployment_environment', (string) config('telemetry.deployment_environment', 'production')),
            ])),
        );

        $sampleRate = (float) $this->configValue('sample_rate', (float) config('telemetry.sample_rate', 1.0));
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
