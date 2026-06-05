<?php

namespace App\Infrastructure\AI\Services\ModelCatalog;

/**
 * Normalizes a provider-specific /models response into ModelCatalogEntry list.
 * One implementation per response format (OpenRouter rich pricing, generic
 * OpenAI-compatible ids-only, etc.).
 */
interface ModelCatalogAdapter
{
    /**
     * @param  array<string, mixed>  $raw  decoded JSON body of the /models response
     * @return list<ModelCatalogEntry>
     */
    public function normalize(array $raw): array;
}
