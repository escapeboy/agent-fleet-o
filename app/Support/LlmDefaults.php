<?php

namespace App\Support;

/**
 * Central resolver for the internal default LLM provider/model used by
 * actions that extract entities, structure signals, or build knowledge
 * graph edges.
 *
 * Cascade order:
 *   1. config('llm_defaults.provider')       — env LLM_DEFAULT_PROVIDER
 *   2. config('llm_providers.default_provider') — Barsy-style override
 *   3. 'bridge_agent'                        — zero-config community default
 *
 * `bridge_agent` is a zero-cost provider routed through the FleetQ Bridge
 * daemon and requires no cloud API key, so it is the only safe ultimate
 * fallback for deployments that never configure a cloud provider.
 */
class LlmDefaults
{
    public static function provider(): string
    {
        $value = config('llm_defaults.provider');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = config('llm_providers.default_provider');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return 'bridge_agent';
    }

    public static function model(): string
    {
        $value = config('llm_defaults.model');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = config('llm_providers.default_model');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return 'claude-haiku-4-5-20251001';
    }
}
