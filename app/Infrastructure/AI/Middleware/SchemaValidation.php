<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use Closure;
use Illuminate\Support\Facades\Log;

class SchemaValidation implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        $response = $next($request);

        if (! $request->isStructured()) {
            return $response;
        }

        // Prism handles schema validation natively for structured output, so a
        // null parsed output is rare for first-class providers. It mainly shows
        // up with custom_endpoint / self-hosted models that ignore native
        // JSON-schema and return prose or fenced JSON. When enabled, re-prompt
        // up to N times for a single valid JSON object before giving up. Each
        // retry runs $next (downstream of this middleware), so it is metered but
        // not budget/idempotency re-checked — max_attempts is kept small.
        if ($response->parsedOutput === null && (bool) config('ai_routing.structured_self_correction.enabled', false)) {
            $maxAttempts = (int) config('ai_routing.structured_self_correction.max_attempts', 1);

            for ($attempt = 1; $attempt <= $maxAttempts && $response->parsedOutput === null; $attempt++) {
                $response = $next($request->withUserPrompt($this->correctionPrompt($request->userPrompt)));
            }

            if ($response->parsedOutput !== null) {
                Log::info('SchemaValidation: self-correction recovered valid structured output', [
                    'provider' => $request->provider,
                    'model' => $request->model,
                    'purpose' => $request->purpose,
                ]);

                return $response;
            }
        }

        // This middleware serves as a secondary check and logs mismatches.
        if ($response->parsedOutput === null) {
            Log::warning('SchemaValidation: structured request returned null parsed output', [
                'provider' => $request->provider,
                'model' => $request->model,
                'purpose' => $request->purpose,
            ]);

            return new AiResponseDTO(
                content: $response->content,
                parsedOutput: $response->parsedOutput,
                usage: $response->usage,
                provider: $response->provider,
                model: $response->model,
                latencyMs: $response->latencyMs,
                schemaValid: false,
                cached: $response->cached,
            );
        }

        return $response;
    }

    private function correctionPrompt(string $original): string
    {
        return $original
            ."\n\n---\n"
            .'Your previous response could not be parsed as a single valid JSON object matching the required schema. '
            .'Respond again with ONLY one valid JSON object that satisfies the schema — no prose, no explanation, no markdown code fences.';
    }
}
