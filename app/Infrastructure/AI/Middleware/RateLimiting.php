<?php

namespace App\Infrastructure\AI\Middleware;

use App\Infrastructure\AI\Contracts\AiMiddlewareInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\Exceptions\RateLimitExceededException;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

class RateLimiting implements AiMiddlewareInterface
{
    public function handle(AiRequestDTO $request, Closure $next): AiResponseDTO
    {
        $key = "ai-gateway:{$request->provider}";

        $maxAttempts = $this->getMaxAttempts($request->provider);
        $decaySeconds = 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            throw new RateLimitExceededException(
                "Rate limit exceeded for provider {$request->provider}. Retry after {$retryAfter}s.",
            );
        }

        RateLimiter::hit($key, $decaySeconds);

        return $next($request);
    }

    private function getMaxAttempts(string $provider): int
    {
        return match ($provider) {
            'anthropic' => 60,  // 60 requests/min
            'openai' => 100,    // 100 requests/min
            'google' => 60,     // 60 requests/min
            default => 30,
        };
    }
}
