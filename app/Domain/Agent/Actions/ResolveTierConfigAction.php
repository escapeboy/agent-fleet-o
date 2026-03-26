<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Enums\ExecutionTier;
use App\Domain\Agent\Models\Agent;

/**
 * Merges ExecutionTier defaults with per-agent overrides to produce a resolved execution config.
 *
 * Precedence (highest → lowest):
 *   1. Per-agent explicit overrides (max_tokens, max_steps, temperature in agents.config)
 *   2. Tier defaults (ExecutionTier::config())
 */
class ResolveTierConfigAction
{
    /**
     * @return array{
     *   model_preference: string,
     *   max_tokens: int,
     *   max_steps: int,
     *   temperature: float,
     *   allow_sub_agents: bool,
     *   planning_depth: int,
     *   tier: ExecutionTier,
     *   thinking_budget: int|null,
     * }
     */
    public function execute(Agent $agent): array
    {
        $config = $agent->config ?? [];
        $tier = ExecutionTier::fromConfig($config);
        $tierDefaults = $tier->config();

        return array_merge($tierDefaults, [
            // Per-agent explicit overrides win over tier defaults
            'max_tokens' => $config['max_tokens'] ?? $tierDefaults['max_tokens'],
            'max_steps' => $config['max_steps'] ?? $tierDefaults['max_steps'],
            'temperature' => $config['temperature'] ?? $tierDefaults['temperature'],
            'thinking_budget' => isset($config['thinking_budget']) ? (int) $config['thinking_budget'] : null,
            'tier' => $tier,
        ]);
    }
}
