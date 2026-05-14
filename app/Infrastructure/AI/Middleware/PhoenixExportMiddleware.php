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
 * Optional last-in-pipeline middleware that exports every LLM call to an Arize
 * Phoenix instance as an OpenInference-shaped OTLP trace.
 *
 * Only active when `PHOENIX_OTLP_ENDPOINT` is configured. Fire-and-forget:
 * dispatches a queued job to the `metrics` queue, never blocks or fails the
 * gateway request. Cached responses are not re-exported (avoid duplicates).
 *
 * Mirrors the existing `LangfuseExportMiddleware` pattern — the two can be
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
            $traceId = $this->randomHex(32);
            $spanId = $this->randomHex(16);

            // Times in nanoseconds — OTLP standard.
            $endNs = (int) (microtime(true) * 1_000_000_000);
            $startNs = $endNs - ($response->latencyMs * 1_000_000);

            $payload = [
                'resourceSpans' => [[
                    'resource' => [
                        'attributes' => $this->attributes->toOtlpAttributes([
                            'service.name' => (string) config('llmops.phoenix.project', 'fleetq'),
                            'service.version' => (string) config('app.version', '1.0.0'),
                        ]),
                    ],
                    'scopeSpans' => [[
                        'scope' => ['name' => 'fleetq.ai-gateway'],
                        'spans' => [[
                            'traceId' => $traceId,
                            'spanId' => $spanId,
                            'name' => $request->purpose ?? 'llm-call',
                            'kind' => 3, // SPAN_KIND_CLIENT
                            'startTimeUnixNano' => (string) $startNs,
                            'endTimeUnixNano' => (string) $endNs,
                            'attributes' => $this->attributes->toOtlpAttributes(
                                $this->attributes->forLlmCall($request, $response),
                            ),
                            'status' => ['code' => 1], // STATUS_CODE_OK
                        ]],
                    ]],
                ]],
            ];

            ExportToPhoenixJob::dispatch(
                payload: $payload,
                endpoint: $endpoint,
                apiKey: (string) config('llmops.phoenix.api_key', ''),
            );
        } catch (\Throwable $e) {
            Log::warning('PhoenixExportMiddleware: failed to build span, swallowing', [
                'error' => $e->getMessage(),
                'purpose' => $request->purpose,
            ]);
        }

        return $response;
    }

    /**
     * Generate `$length` lowercase hex chars (no dashes — OTLP traceId/spanId format).
     */
    private function randomHex(int $length): string
    {
        $bytes = (int) ceil($length / 2);

        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }
}
