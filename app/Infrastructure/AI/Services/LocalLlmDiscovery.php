<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LocalLlmDiscovery
{
    /**
     * Check whether the configured (or provided) endpoint is reachable.
     * Uses a short timeout so it never blocks page render.
     */
    public function isReachable(string $provider, ?string $baseUrl = null): bool
    {
        $url = $baseUrl ?? $this->resolveDefaultUrl($provider);

        if (! $url) {
            return false;
        }

        $checkUrl = $provider === 'ollama'
            ? rtrim($url, '/').'/api/tags'   // Ollama native health endpoint
            : rtrim($url, '/').'/models';    // OpenAI-compat standard

        return rescue(
            fn () => Http::timeout(3)->get($checkUrl)->successful(),
            false,
        );
    }

    /**
     * Discover available models from the endpoint, with Redis caching.
     *
     * @return list<string>
     */
    public function discoverModels(string $provider, string $baseUrl, ?string $apiKey = null): array
    {
        $cacheKey = "local_llm_models:{$provider}:".md5($baseUrl);
        $ttl = 60; // seconds

        return Cache::remember($cacheKey, $ttl, fn () => $this->fetchModels($provider, $baseUrl, $apiKey));
    }

    /**
     * Bust the model discovery cache for a provider+URL combination.
     */
    public function bustCache(string $provider, string $baseUrl): void
    {
        Cache::forget("local_llm_models:{$provider}:".md5($baseUrl));
    }

    /**
     * @return list<string>
     */
    private function fetchModels(string $provider, string $baseUrl, ?string $apiKey): array
    {
        try {
            $endpoint = $provider === 'ollama'
                ? rtrim($baseUrl, '/').'/api/tags'
                : rtrim($baseUrl, '/').'/models';

            $request = Http::timeout(5);

            if ($apiKey) {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get($endpoint);

            if (! $response->successful()) {
                return [];
            }

            // Ollama /api/tags: {"models": [{"name": "llama3:8b", ...}]}
            // OpenAI-compat /models: {"data": [{"id": "..."}]}
            return $provider === 'ollama'
                ? collect($response->json('models', []))->pluck('name')->values()->all()
                : collect($response->json('data', []))->pluck('id')->values()->all();
        } catch (\Throwable) {
            return [];
        }
    }

    private function resolveDefaultUrl(string $provider): ?string
    {
        return config("llm_providers.{$provider}.default_url");
    }
}
