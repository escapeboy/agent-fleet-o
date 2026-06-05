<?php

namespace App\Infrastructure\AI\Services\ModelCatalog;

/**
 * Generic OpenAI-compatible /v1/models response (Groq, Fireworks, Mistral,
 * DeepSeek, xAI, …).
 *
 * Shape: { "data": [ { "id": "..." } ] } — ids only, no pricing. Pricing for
 * these providers stays in config('llm_pricing.providers.*'); the catalog only
 * supplies the live model id list. Entries are left unpriced (null) so the
 * caller resolves cost from config rather than billing $0.
 */
class OpenAiCompatCatalogAdapter implements ModelCatalogAdapter
{
    public function normalize(array $raw): array
    {
        $rows = $raw['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $entries = [];

        foreach ($rows as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $entries[] = new ModelCatalogEntry($id, $id, null, null, null);
        }

        return $entries;
    }
}
