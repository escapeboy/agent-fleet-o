<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;

class ProviderResolver
{
    /**
     * Resolve provider and model using the hierarchy:
     * 1. Skill-level override
     * 2. Agent-level override
     * 3. Team workspace default
     * 4. Platform default
     *
     * @return array{provider: string, model: string}
     */
    public function resolve(
        ?Skill $skill = null,
        ?Agent $agent = null,
        ?Team $team = null,
    ): array {
        // 1. Skill-level override
        if ($skill) {
            $config = $skill->configuration ?? [];
            if (! empty($config['provider']) && ! empty($config['model'])) {
                return [
                    'provider' => $config['provider'],
                    'model' => $config['model'],
                ];
            }
        }

        // 2. Agent-level override
        if ($agent && $agent->provider && $agent->model) {
            return [
                'provider' => $agent->provider,
                'model' => $agent->model,
            ];
        }

        // 3. Team workspace default
        if ($team) {
            $settings = $team->settings ?? [];
            if (! empty($settings['default_llm_provider']) && ! empty($settings['default_llm_model'])) {
                return [
                    'provider' => $settings['default_llm_provider'],
                    'model' => $settings['default_llm_model'],
                ];
            }
        }

        // 4. Platform default
        return [
            'provider' => config('llm_pricing.default_provider', 'anthropic'),
            'model' => config('llm_pricing.default_model', 'claude-sonnet-4-5'),
        ];
    }

    /**
     * Get all available providers and their models from config.
     *
     * @return array<string, array{name: string, models: array}>
     */
    public function availableProviders(): array
    {
        return config('llm_providers', []);
    }

    /**
     * Get models for a specific provider.
     *
     * @return array<string, array{label: string, input_cost: float, output_cost: float}>
     */
    public function modelsForProvider(string $provider): array
    {
        return config("llm_providers.{$provider}.models", []);
    }
}
