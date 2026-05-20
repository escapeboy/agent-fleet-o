<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;
use App\Infrastructure\AI\Services\OpenInferenceAttributes;
use App\Infrastructure\AI\Services\PhoenixTraceContext;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Optional last-in-pipeline middleware that exports every LLM call to an
 * Arize Phoenix instance as an OpenInference-shaped OTLP/protobuf span.
 *
 * Behavior:
 *   - No-op when `llmops.phoenix.enabled=false`
 *   - Cached responses are not re-exported (avoid duplicates)
 *   - Sampling: when no parent context is active, roll the dice on
 *     `llmops.phoenix.sample_rate`. When a parent IS active (set on the DTO
 *     or in PhoenixTraceContext), always emit so the trace tree stays whole
 *   - Masking: when `llmops.phoenix.mask_content=true`, prompt/response text
 *     is redacted before export (token counts + metadata remain)
 *   - Parent linking: parentTraceId/parentSpanId from the DTO win over
 *     PhoenixTraceContext (explicit > implicit)
 */
class PhoenixExportMiddleware implements AiMiddlewareInterface
{
    public function __construct(
        private readonly OpenInferenceAttributes $attributes,
        private readonly PhoenixTraceContext $traceContext,
    ) {}

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
            // Resolve parent context: explicit DTO fields win, else active
            // PhoenixTraceContext root, else stand-alone (parent=null).
            $parentTraceId = $request->parentTraceId ?? $this->traceContext->currentTraceId();
            $parentSpanId = $request->parentSpanId ?? $this->traceContext->currentSpanId();

            if (! $this->shouldEmit($parentTraceId !== null)) {
                return $response;
            }

            $traceId = $parentTraceId ?? $this->randomHex(32);
            $spanId = $this->randomHex(16);

            $endNanos = (int) (microtime(true) * 1_000_000_000);
            $startNanos = $endNanos - ($response->latencyMs * 1_000_000);

            $maskContent = (bool) config('llmops.phoenix.mask_content', false);

            ExportToPhoenixJob::dispatch(
                endpoint: $endpoint,
                spanName: $request->purpose ?? 'llm-call',
                attributes: $this->attributes->forLlmCall($request, $response, $maskContent),
                startNanos: $startNanos,
                endNanos: $endNanos,
                apiKey: (string) config('llmops.phoenix.api_key', ''),
                project: (string) config('llmops.phoenix.project', 'fleetq'),
                traceId: $traceId,
                spanId: $spanId,
                parentSpanId: $parentSpanId,
            );
        } catch (\Throwable $e) {
            Log::warning('PhoenixExportMiddleware: failed to enqueue export, swallowing', [
                'error' => $e->getMessage(),
                'purpose' => $request->purpose,
            ]);
        }

        return $response;
    }

    /**
     * Children of an active parent always emit. Stand-alone spans roll the
     * configured sample rate.
     */
    private function shouldEmit(bool $hasParent): bool
    {
        if ($hasParent) {
            return true;
        }

        $rate = (float) config('llmops.phoenix.sample_rate', 1.0);

        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function randomHex(int $length): string
    {
        $bytes = (int) ceil($length / 2);

        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }
}
