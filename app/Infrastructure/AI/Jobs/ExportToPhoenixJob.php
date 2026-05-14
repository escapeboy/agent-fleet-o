<?php

namespace App\Infrastructure\AI\Jobs;

use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;
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
 * Uses the OTel PHP SDK to serialize a single span to OTLP-protobuf and POST
 * it to `{endpoint}/v1/traces`. Phoenix's OTLP HTTP receiver rejects JSON
 * (415 Unsupported Media Type) so protobuf is mandatory.
 *
 * Docker-internal endpoints (e.g. `http://phoenix:6006`) are allowed only
 * when `PHOENIX_ALLOW_HTTP=true`. Public endpoints MUST be HTTPS.
 *
 * Failure never affects the originating AI request — exceptions are caught
 * and logged.
 */
class ExportToPhoenixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    /**
     * @param  array<string, scalar|null>  $attributes  flat OpenInference attribute map
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $spanName,
        private readonly array $attributes,
        private readonly int $startNanos,
        private readonly int $endNanos,
        private readonly string $apiKey = '',
        private readonly string $project = 'fleetq',
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

        try {
            $tracer = $tracerProvider->getTracer('fleetq.ai-gateway');

            $span = $tracer->spanBuilder($this->spanName)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp($this->startNanos)
                ->startSpan();

            foreach ($this->attributes as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $span->setAttribute($key, $value);
            }

            $span->end($this->endNanos);
        } finally {
            // Forces span flush through the SimpleSpanProcessor → SpanExporter
            // → transport → Phoenix. Without shutdown() the span sits in the
            // processor buffer and the worker exits before flushing.
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
}
