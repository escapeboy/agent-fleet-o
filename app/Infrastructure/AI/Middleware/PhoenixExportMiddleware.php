<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Services\OpenInferenceAttributes;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Optional last-in-pipeline middleware that exports every LLM call to an
 * Arize Phoenix instance as an OpenInference-shaped OTLP/protobuf span.
 *
 * Only active when `PHOENIX_OTLP_ENDPOINT` is configured. Fire-and-forget:
 * dispatches a queued `ExportToPhoenixJob` (which uses the OTel PHP SDK to
 * serialize protobuf). Never blocks or fails the gateway request. Cached
 * responses are not re-exported.
 *
 * Mirrors the existing `LangfuseExportMiddleware` pattern — both can be
 * wired in the pipeline simultaneously.
 */
class PhoenixExportMiddleware implements AiMiddlewareInterface
{
    public function __construct(private readonly OpenInferenceAttributes $attributes) {}

    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        $response = $next($request);

        $endpoint = (string) config('llmops.phoenix.endpoint', '');
        $enabled = (bool) config('llmops.phoenix.enabled', false);

        if (! $enabled || $endpoint === '') {
            return $response;
        }

        if ($response->cached) {
            return $response;
        }

        try {
            // Timestamps in ns. End = now; start = end - measured latency.
            $endNanos = (int) (microtime(true) * 1_000_000_000);
            $startNanos = $endNanos - ($response->latencyMs * 1_000_000);

            ExportToPhoenixJob::dispatch(
                endpoint: $endpoint,
                spanName: $request->purpose ?? 'llm-call',
                attributes: $this->attributes->forLlmCall($request, $response),
                startNanos: $startNanos,
                endNanos: $endNanos,
                apiKey: (string) config('llmops.phoenix.api_key', ''),
                project: (string) config('llmops.phoenix.project', 'fleetq'),
            );
        } catch (\Throwable $e) {
            Log::warning('PhoenixExportMiddleware: failed to enqueue export, swallowing', [
                'error' => $e->getMessage(),
                'purpose' => $request->purpose,
            ]);
        }

        return $response;
    }
}
