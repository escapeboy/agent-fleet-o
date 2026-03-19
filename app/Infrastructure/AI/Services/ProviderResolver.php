<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Bridge\Models\BridgeConnection;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\Skill;
use App\Models\GlobalSetting;
use Illuminate\Support\Collection;

class ProviderResolver
{
    /**
     * Resolve provider and model using the hierarchy:
     * 1. Skill split-model override (if model_selection_mode === 'split')
     * 2. Skill unified override
     * 3. Agent-level override
     * 4. Team workspace default
     * 5. Platform default
     *
     * @param  string  $purpose  'run' (default, production) or 'build' (design/testing)
     * @return array{provider: string, model: string}
     */
    public function resolve(
        ?Skill $skill = null,
        ?Agent $agent = null,
        ?Team $team = null,
        string $purpose = 'run',
    ): array {
        // 1. Skill-level override
        if ($skill) {
            $config = $skill->configuration ?? [];

            // Split model: separate build/run models
            if (($config['model_selection_mode'] ?? 'unified') === 'split') {
                $key = $purpose === 'build' ? 'build_model' : 'run_model';
                $modelConfig = $config[$key] ?? null;

                if ($modelConfig && ! empty($modelConfig['provider']) && ! empty($modelConfig['model'])) {
                    return [
                        'provider' => $modelConfig['provider'],
                        'model' => $modelConfig['model'],
                    ];
                }
            }

            // Unified: single model for all executions
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
                ->whereNotIn('provider', ['custom_endpoint', 'ollama', 'openai_compatible', 'litellm_proxy'])
                ->pluck('provider')
                ->flip()
                ->all()
            : [];

        foreach ($providers as $key => $provider) {
            // HTTP-based local LLM providers (Ollama, OpenAI-compatible)
            if (! empty($provider['http_local'])) {
                if (! $localLlmEnabled) {
                    unset($providers[$key]);
                } else {
                    // Replace static model list with live models fetched from the endpoint.
                    // For Ollama: queries /api/tags. For OpenAI-compat: queries /models.
                    // Returns empty array if the endpoint is unreachable — never shows
                    // hardcoded model names that may not actually be pulled/available.
                    $providers[$key]['models'] = $this->fetchHttpLocalModels($key, $team);
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

            // Bridge-backed providers — populate models from active BridgeConnection
            if (! empty($provider['bridge'])) {
                if ($key === 'bridge_agent') {
                    $agents = $this->activeBridgeAgents();
                    if (empty($agents)) {
                        unset($providers[$key]);
                    } else {
                        $providers[$key]['models'] = $agents;
                    }
                } else {
                    // bridge_llm and any future bridge providers: remove if no active connection
                    try {
                        $hasActiveBridge = BridgeConnection::active()->exists();
                    } catch (\Throwable) {
                        $hasActiveBridge = false;
                    }
                    if (! $hasActiveBridge) {
                        unset($providers[$key]);
                    }
                }

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
     * For HTTP-local providers (ollama, openai_compatible, litellm_proxy) returns only
     * dynamically discovered models from the endpoint — never the hardcoded static list,
     * because showing models that are not actually available is misleading.
     *
     * @return array<string, array{label: string, input_cost: float, output_cost: float}>
     */
    public function modelsForProvider(string $provider, ?Team $team = null): array
    {
        if (! config("llm_providers.{$provider}.http_local")) {
            return config("llm_providers.{$provider}.models", []);
        }

        return $this->fetchHttpLocalModels($provider, $team);
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

    /**
     * Fetch the live model list for an HTTP-based local LLM provider (Ollama, OpenAI-compatible).
     *
     * Uses the team's configured TeamProviderCredential base_url when available,
     * falling back to the provider's config default_url. Results are cached for 60 s
     * via LocalLlmDiscovery so page renders stay fast.
     *
     * Returns an empty array when the endpoint is unreachable or returns no models —
     * never falls back to the hardcoded static list, because showing models that are
     * not actually pulled/available is misleading.
     *
     * @return array<string, array{label: string, input_cost: int, output_cost: int}>
     */
    private function fetchHttpLocalModels(string $provider, ?Team $team): array
    {
        $baseUrl = null;
        $apiKey = null;

        if ($team) {
            $credential = TeamProviderCredential::where('team_id', $team->id)
                ->where('provider', $provider)
                ->where('is_active', true)
                ->first();

            if ($credential) {
                $baseUrl = $credential->credentials['base_url'] ?? null;
                $apiKey = $credential->credentials['api_key'] ?? null;
            }
        }

        // Fall back to the provider's default URL from config (e.g. localhost:11434 for Ollama)
        $baseUrl ??= config("llm_providers.{$provider}.default_url");

        if (! $baseUrl) {
            return [];
        }

        $discovered = app(LocalLlmDiscovery::class)->discoverModels($provider, $baseUrl, $apiKey ?: null);

        return collect($discovered)
            ->mapWithKeys(fn (string $modelId) => [
                $modelId => ['label' => $modelId, 'input_cost' => 0, 'output_cost' => 0],
            ])
            ->all();
    }

    /**
     * Known model lists per bridge agent key.
     * Models are shown as "AgentName — Model Label" in the assistant panel.
     */
    private const BRIDGE_AGENT_MODELS = [
        'claude-code' => [
            'claude-sonnet-4-5' => 'Claude Code — Sonnet 4.5',
            'claude-haiku-4-5' => 'Claude Code — Haiku 4.5',
            'claude-opus-4-6' => 'Claude Code — Opus 4.6',
        ],
        'codex' => [
            'o4-mini' => 'Codex — o4-mini',
            'o3' => 'Codex — o3',
            'o1' => 'Codex — o1',
        ],
        'gemini' => [
            'gemini-2.5-flash' => 'Gemini CLI — 2.5 Flash',
            'gemini-2.5-pro' => 'Gemini CLI — 2.5 Pro',
        ],
        'aider' => [
            'claude-sonnet-4-5' => 'Aider — Sonnet 4.5',
            'claude-haiku-4-5' => 'Aider — Haiku 4.5',
            'gpt-4o' => 'Aider — GPT-4o',
            'gpt-4o-mini' => 'Aider — GPT-4o Mini',
            'gemini-2.5-flash' => 'Aider — Gemini 2.5 Flash',
        ],
    ];

    /**
     * Get models for the bridge_agent provider from the active BridgeConnection.
     *
     * Returns compound keys in the form "agent_key:model" (e.g. "claude-code:claude-sonnet-4-5")
     * for agents with known model lists. For unknown agents, falls back to a single entry
     * keyed by agent_key alone so the agent is still selectable.
     *
     * @return array<string, array{label: string, input_cost: int, output_cost: int}>
     */
    private function activeBridgeAgents(): array
    {
        try {
            $connection = BridgeConnection::active()->latest('connected_at')->first();
        } catch (\Throwable) {
            return [];
        }

        if (! $connection) {
            return [];
        }

        $models = [];
        foreach ($connection->agents() as $agent) {
            if (! ($agent['found'] ?? false)) {
                continue;
            }
            $key = $agent['key'];
            $agentName = $agent['name'] ?? $key;

            $knownModels = self::BRIDGE_AGENT_MODELS[$key] ?? null;

            if ($knownModels) {
                foreach ($knownModels as $modelKey => $modelLabel) {
                    $models["{$key}:{$modelKey}"] = [
                        'label' => $modelLabel,
                        'input_cost' => 0,
                        'output_cost' => 0,
                    ];
                }
            } else {
                // Agent with no known model list (kiro, cursor, cline, opencode, …)
                // Show as a single selectable option; the agent manages its own model.
                $models[$key] = [
                    'label' => $agentName,
                    'input_cost' => 0,
                    'output_cost' => 0,
                ];
            }
        }

        return $models;
    }
}
