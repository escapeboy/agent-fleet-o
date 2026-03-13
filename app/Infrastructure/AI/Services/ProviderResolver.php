<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\Skill;
use App\Models\GlobalSetting;
use Illuminate\Support\Collection;

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

        // 4. GlobalSetting (configured via Settings page)
        $globalProvider = GlobalSetting::get('default_llm_provider');
        $globalModel = GlobalSetting::get('default_llm_model');
        if ($globalProvider && $globalModel) {
            return [
                'provider' => $globalProvider,
                'model' => $globalModel,
            ];
        }

        // 5. Platform default
        return [
            'provider' => config('llm_pricing.default_provider', 'anthropic'),
            'model' => config('llm_pricing.default_model', 'claude-sonnet-4-5'),
        ];
    }

    /**
     * Get all available providers and their models from config.
     * Local agents are only included when enabled and detected on the system.
     *
     * @return array<string, array{name: string, models: array}>
     */
    public function availableProviders(?Team $team = null): array
    {
        $providers = config('llm_providers', []);

        // Filter providers based on enabled flags and detection
        $localAgentsEnabled = config('local_agents.enabled');
        $localLlmEnabled = config('local_llm.enabled', false);
        $detected = null;

        // Pre-load team's active BYOK provider keys for cloud provider filtering
        $teamByokProviders = $team
            ? TeamProviderCredential::where('team_id', $team->id)
                ->where('is_active', true)
                ->whereNotIn('provider', ['custom_endpoint', 'ollama', 'openai_compatible'])
                ->pluck('provider')
                ->flip()
                ->all()
            : [];

        foreach ($providers as $key => $provider) {
            // HTTP-based local LLM providers (Ollama, OpenAI-compatible)
            if (! empty($provider['http_local'])) {
                if (! $localLlmEnabled) {
                    unset($providers[$key]);
                }

                continue;
            }

            // CLI-based local agent providers (Codex, Claude Code)
            if (! empty($provider['local'])) {
                if (! $localAgentsEnabled) {
                    unset($providers[$key]);

                    continue;
                }

                // Lazy-detect once
                if ($detected === null) {
                    $detected = app(LocalAgentDiscovery::class)->detect();
                }

                $agentKey = $provider['agent_key'] ?? $key;

                if (! isset($detected[$agentKey])) {
                    unset($providers[$key]);
                }

                continue;
            }

            // Bridge-backed providers — handled separately, skip cloud key check
            if (! empty($provider['bridge'])) {
                continue;
            }

            // Cloud providers: only show if a platform API key or team BYOK key is configured
            $platformKey = config("services.platform_api_keys.{$key}");
            if (! $platformKey && ! isset($teamByokProviders[$key])) {
                unset($providers[$key]);
            }
        }

        return $providers;
    }

    /**
     * Get models for a specific provider.
     * For HTTP-local providers (ollama, openai_compatible), merges static config models
     * with dynamically discovered models from the team's configured endpoint.
     *
     * @return array<string, array{label: string, input_cost: float, output_cost: float}>
     */
    public function modelsForProvider(string $provider, ?Team $team = null): array
    {
        $static = config("llm_providers.{$provider}.models", []);

        if (! config("llm_providers.{$provider}.http_local") || ! $team) {
            return $static;
        }

        $credential = TeamProviderCredential::where('team_id', $team->id)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        if (! $credential) {
            return $static;
        }

        $baseUrl = $credential->credentials['base_url'] ?? null;
        if (! $baseUrl) {
            return $static;
        }

        $apiKey = $credential->credentials['api_key'] ?? null;
        $discovered = app(LocalLlmDiscovery::class)->discoverModels($provider, $baseUrl, $apiKey ?: null);

        foreach ($discovered as $modelId) {
            if (! isset($static[$modelId])) {
                $static[$modelId] = ['label' => $modelId, 'input_cost' => 0, 'output_cost' => 0];
            }
        }

        return $static;
    }

    /**
     * Get active custom AI endpoints for a team.
     *
     * Returns credential records with name, masked key, and base_url.
     * These appear as selectable providers in agent/skill forms.
     *
     * @return Collection<int, TeamProviderCredential>
     */
    public function customEndpointsForTeam(?Team $team): Collection
    {
        if (! $team) {
            return collect();
        }

        return TeamProviderCredential::where('team_id', $team->id)
            ->where('provider', 'custom_endpoint')
            ->where('is_active', true)
            ->get();
    }
}
