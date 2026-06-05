<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\Services\ModelCatalog\ModelCatalogAdapter;
use App\Infrastructure\AI\Services\ModelCatalog\ModelCatalogEntry;
use App\Infrastructure\AI\Services\ModelCatalog\OpenAiCompatCatalogAdapter;
use App\Infrastructure\AI\Services\ModelCatalog\OpenRouterCatalogAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Discovers the live model catalog for a managed multi-model provider
 * (OpenRouter, Groq, Fireworks, …) from its /models endpoint, with Redis caching.
 *
 * Mirror of LocalLlmDiscovery, but for managed (non-local) providers: it reads
 * the endpoint + adapter from llm_providers config rather than a per-team URL,
 * and normalizes pricing. Never blocks render — on any failure it returns an
 * empty list and the caller falls back to the static config catalog.
 */
class ManagedModelDiscovery
{
    /**
     * Discover normalized models for a provider, Redis-cached.
     *
     * @return list<ModelCatalogEntry>
     */
    public function discover(string $provider, ?string $apiKey = null, bool $force = false): array
    {
        $endpoint = config("llm_providers.{$provider}.models_endpoint");
        if (! is_string($endpoint) || $endpoint === '') {
            return [];
        }

        $cacheKey = $this->cacheKey($provider, $endpoint);

        if ($force) {
            Cache::forget($cacheKey);
        }

        $ttl = (int) config('model_catalog.cache_ttl', 3600);

        /** @var list<array{id:string,label:string,in:?float,out:?float,ctx:?int}> $cached */
        $cached = Cache::remember(
            $cacheKey,
            $ttl,
            fn () => array_map(
                fn (ModelCatalogEntry $e) => [
                    'id' => $e->id, 'label' => $e->label,
                    'in' => $e->inputUsdPerMtok, 'out' => $e->outputUsdPerMtok, 'ctx' => $e->context,
                ],
                $this->fetch($provider, $endpoint, $apiKey),
            ),
        );

        return array_map(
            fn (array $r) => new ModelCatalogEntry($r['id'], $r['label'], $r['in'], $r['out'], $r['ctx']),
            $cached,
        );
    }

    /**
     * Drop the cached catalog for a provider so the next discover() refetches.
     */
    public function bustCache(string $provider): void
    {
        $endpoint = config("llm_providers.{$provider}.models_endpoint");
        if (is_string($endpoint) && $endpoint !== '') {
            Cache::forget($this->cacheKey($provider, $endpoint));
        }
    }

    /**
     * Fetch + normalize without caching. Returns [] on any HTTP/parse failure.
     *
     * @return list<ModelCatalogEntry>
     */
    private function fetch(string $provider, string $endpoint, ?string $apiKey): array
    {
        try {
            $request = Http::timeout((int) config('model_catalog.timeout', 5));

            if (config("llm_providers.{$provider}.models_endpoint_auth") === 'bearer' && $apiKey) {
                $request = $request->withToken($apiKey);
            }

            $response = $request->get($endpoint);

            if (! $response->successful()) {
                return [];
            }

            return $this->adapterFor($provider)->normalize($response->json() ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    private function adapterFor(string $provider): ModelCatalogAdapter
    {
        return match (config("llm_providers.{$provider}.catalog_adapter")) {
            'openrouter' => new OpenRouterCatalogAdapter,
            default => new OpenAiCompatCatalogAdapter,
        };
    }

    private function cacheKey(string $provider, string $endpoint): string
    {
        return "managed_model_catalog:{$provider}:".md5($endpoint);
    }
}
