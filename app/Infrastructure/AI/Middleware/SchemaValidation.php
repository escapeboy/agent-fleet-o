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

        // Prism handles schema validation natively for structured output.
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
}
