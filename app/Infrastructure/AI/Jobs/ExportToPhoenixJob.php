<?php

namespace App\Infrastructure\AI\Jobs;

use App\Domain\Shared\Services\SsrfGuard;
use App\Infrastructure\Observability\Prometheus\MetricEmitter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Fire-and-forget OTLP/protobuf exporter for Phoenix.
 *
 * Builds one span with explicit traceId/spanId/parentSpanId and POSTs it via
 * the OTel PHP SDK. Phoenix's OTLP HTTP receiver is protobuf-only (returns
 * 415 for application/json) — see `feedback/phoenix-otlp-http-is-protobuf-only`.
 *
 * Failure never affects the originating request — exceptions caught + logged.
 * Metrics emitted via the MetricEmitter facade (success/failure counter +
 * latency histogram) so Grafana can plot export health.
 */
class ExportToPhoenixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    /**
     * @param  array<string, scalar|null>  $attributes  flat OpenInference attribute map
     * @param  string  $traceId  32-hex
     * @param  string  $spanId  16-hex
     * @param  string|null  $parentSpanId  16-hex when nesting under a parent span
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $spanName,
        private readonly array $attributes,
        private readonly int $startNanos,
        private readonly int $endNanos,
        private readonly string $apiKey = '',
        private readonly string $project = 'fleetq',
        private readonly ?string $traceId = null,
        private readonly ?string $spanId = null,
        private readonly ?string $parentSpanId = null,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(SsrfGuard $ssrfGuard): void
    {
        if ($this->endpoint === '') {
            return;
        }

        $tracesUrl = rtrim($this->endpoint, '/').'/v1/traces';
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);
        $allowHttp = (bool) config('llmops.phoenix.allow_http', false);

        if ($scheme !== 'https' && ! $allowHttp) {
            Log::warning('ExportToPhoenixJob: non-https endpoint blocked. Set PHOENIX_ALLOW_HTTP=true for docker-internal sidecars.', [
                'endpoint' => $this->endpoint,
            ]);

            return;
        }

        if ($scheme === 'https') {
            $ssrfGuard->assertPublicUrl($tracesUrl);
        }

        $headers = $this->apiKey !== ''
            ? ['Authorization' => 'Bearer '.$this->apiKey]
            : [];

        $transport = (new OtlpHttpTransportFactory)->create(
            $tracesUrl,
            'application/x-protobuf',
            $headers,
        );

        $exporter = new SpanExporter($transport);

        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                'service.name' => $this->project,
                'service.namespace' => 'fleetq',
            ])),
        );

        $tracerProvider = new TracerProvider(
            new SimpleSpanProcessor($exporter),
            null,
            $resource,
        );

        $startedAt = microtime(true);

        try {
            $tracer = $tracerProvider->getTracer('fleetq.ai-gateway');

            $builder = $tracer->spanBuilder($this->spanName)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp($this->startNanos);

            // When a parent span is supplied, seed a SpanContext as the parent
            // so OTel's trace propagation links this span to the correct trace.
            // Otherwise the SDK auto-generates a fresh trace.
            if ($this->traceId !== null && $this->parentSpanId !== null) {
                $parentContext = SpanContext::create(
                    $this->traceId,
                    $this->parentSpanId,
                    TraceFlags::SAMPLED,
                );

                $contextWithParent = Span::wrap($parentContext)
                    ->storeInContext(Context::getCurrent());

                $builder = $builder->setParent($contextWithParent);
            }

            $span = $builder->startSpan();

            foreach ($this->attributes as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $span->setAttribute($key, $value);
            }

            $span->end($this->endNanos);

            $this->recordMetric('success', microtime(true) - $startedAt);
        } catch (\Throwable $e) {
            $this->recordMetric('failure', microtime(true) - $startedAt);
            throw $e;
        } finally {
            $tracerProvider->shutdown();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('ExportToPhoenixJob: export failed', [
            'error' => $e->getMessage(),
            'endpoint' => $this->endpoint,
            'span' => $this->spanName,
        ]);
    }

    private function recordMetric(string $outcome, float $latencySeconds): void
    {
        // Lazy resolve so the job stays unit-testable without bootstrapping
        // the Prometheus stack (which the metrics façade boots lazily anyway).
        try {
            app(MetricEmitter::class)
                ->phoenixExportCompleted($outcome, $latencySeconds * 1000.0);
        } catch (\Throwable) {
            // MetricEmitter not bound (tests, install wizard) — skip.
        }
    }
}
