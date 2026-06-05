<?php

namespace App\Infrastructure\AI\Services\ModelCatalog;

/**
 * OpenRouter /api/v1/models response.
 *
 * Shape: { "data": [ { id, name, context_length, pricing: { prompt, completion } } ] }
 * `pricing.prompt` / `pricing.completion` are USD **per token** (string) — multiply
 * by 1e6 for USD per million tokens. A missing `pricing` block means unpriced
 * (null), distinct from a free model priced explicitly at "0".
 */
class OpenRouterCatalogAdapter implements ModelCatalogAdapter
{
    public function normalize(array $raw): array
    {
        $rows = $raw['data'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $entries = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $label = is_string($row['name'] ?? null) && $row['name'] !== ''
                ? $row['name']
                : $id;

            $pricing = $row['pricing'] ?? null;
            $input = $this->perMillion($pricing['prompt'] ?? null);
            $output = $this->perMillion($pricing['completion'] ?? null);

            $context = isset($row['context_length']) && is_numeric($row['context_length'])
                ? (int) $row['context_length']
                : null;

            $entries[] = new ModelCatalogEntry($id, $label, $input, $output, $context);
        }

        return $entries;
    }

    /**
     * USD-per-token (string|number) → USD-per-million-tokens. Null when absent
     * so the caller can distinguish "unpriced" from "free" (explicit 0).
     */
    private function perMillion(mixed $perToken): ?float
    {
        if ($perToken === null || $perToken === '') {
            return null;
        }

        if (! is_numeric($perToken)) {
            return null;
        }

        return (float) $perToken * 1_000_000.0;
    }
}
