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

    /**
     * Platform default provider — the last step of ProviderResolver's hierarchy,
     * used when no skill, agent, team or GlobalSetting picks one.
     */
    public static function platformProvider(): string
    {
        $value = config('llm_pricing.default_provider');

        return is_string($value) && $value !== '' ? $value : 'anthropic';
    }

    /**
     * Platform default model for a provider: llm_pricing.default_models.{provider},
     * then llm_pricing.default_model for the platform provider, then the first
     * entry of the provider's catalog. The catalog keeps a model the provider
     * still serves at the top, so there is no model id literal to go stale here.
     */
    public static function platformModel(?string $provider = null): string
    {
        $provider ??= self::platformProvider();

        $value = config("llm_pricing.default_models.{$provider}");
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if ($provider === self::platformProvider()) {
            $value = config('llm_pricing.default_model');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        $first = array_key_first(config("llm_providers.{$provider}.models") ?? []);
        if (is_string($first)) {
            return $first;
        }

        // Provider without a static catalog (bridge, custom endpoint): use the
        // platform provider's default rather than inventing a name for it.
        return $provider === self::platformProvider() ? '' : self::platformModel();
    }
}
