<?php

namespace App\Domain\Agent\Enums;

/**
 * Governs agent execution quality along four axes: model selection, max output tokens,
 * max agentic steps, and sub-agent permission.
 *
 * Stored per-agent in agents.config['execution_tier']. Defaults to Standard when absent.
 */
enum ExecutionTier: string
{
    case Flash = 'flash';
    case Standard = 'standard';
    case Pro = 'pro';
    case Ultra = 'ultra';

    /**
     * @return array{
     *   model_preference: string,
     *   max_tokens: int,
     *   max_steps: int,
     *   temperature: float,
     *   allow_sub_agents: bool,
     *   planning_depth: int,
     * }
     */
    public function config(): array
    {
        return match ($this) {
            self::Flash => [
                'model_preference' => 'claude-haiku-4-5',
                'max_tokens' => 2048,
                'max_steps' => 5,
                'temperature' => 0.3,
                'allow_sub_agents' => false,
                'planning_depth' => 1,
            ],
            self::Standard => [
                'model_preference' => 'claude-sonnet-4-5',
                'max_tokens' => 4096,
                'max_steps' => 10,
                'temperature' => 0.7,
                'allow_sub_agents' => false,
                'planning_depth' => 2,
            ],
            self::Pro => [
                'model_preference' => 'claude-sonnet-4-5',
                'max_tokens' => 8192,
                'max_steps' => 20,
                'temperature' => 0.7,
                'allow_sub_agents' => true,
                'planning_depth' => 3,
            ],
            self::Ultra => [
                'model_preference' => 'claude-opus-4-6',
                'max_tokens' => 8192,
                'max_steps' => 50,
                'temperature' => 0.8,
                'allow_sub_agents' => true,
                'planning_depth' => 5,
            ],
        };
    }

    /**
     * Resolve the tier from an agent config array.
     * Defaults to Standard when the key is missing or invalid.
     */
    public static function fromConfig(array $config): self
    {
        return self::tryFrom($config['execution_tier'] ?? 'standard') ?? self::Standard;
    }

    /**
     * Human-readable label for UI display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Flash => 'Flash — Fast & cheap (Haiku)',
            self::Standard => 'Standard — Balanced (Sonnet)',
            self::Pro => 'Pro — Deep reasoning (Sonnet + sub-agents)',
            self::Ultra => 'Ultra — Maximum capability (Opus + sub-agents)',
        };
    }

    /**
     * Short label for badges.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Flash => 'Flash',
            self::Standard => 'Standard',
            self::Pro => 'Pro',
            self::Ultra => 'Ultra',
        };
    }

    /**
     * Tailwind badge colour classes.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Flash => 'bg-gray-100 text-gray-600',
            self::Standard => 'bg-blue-100 text-blue-700',
            self::Pro => 'bg-purple-100 text-purple-700',
            self::Ultra => 'bg-amber-100 text-amber-700',
        };
    }
}
