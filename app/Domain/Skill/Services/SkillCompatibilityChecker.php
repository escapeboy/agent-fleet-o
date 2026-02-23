<?php

namespace App\Domain\Skill\Services;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Exceptions\SkillProviderIncompatibleException;
use App\Domain\Skill\Models\Skill;

class SkillCompatibilityChecker
{
    /**
     * Check if a skill's provider requirements are satisfied by a team's available providers.
     *
     * @return array{compatible: bool, warnings: string[], suggested_model: string|null}
     */
    public function check(Skill $skill, Team $team): array
    {
        $requirements = $skill->provider_requirements ?? [];

        if (empty($requirements)) {
            return ['compatible' => true, 'warnings' => [], 'suggested_model' => null];
        }

        $available = $this->getAvailableProviders($team);
        $requiredProviders = $requirements['required_providers'] ?? [];
        $warnings = [];
        $suggestedModel = null;

        // Check required providers
        foreach ($requiredProviders as $provider) {
            if (! in_array($provider, $available)) {
                return [
                    'compatible' => false,
                    'warnings' => ["Provider '{$provider}' not available. Configure BYOK or platform key."],
                    'suggested_model' => null,
                ];
            }
        }

        // Check tested models (warnings only, not blocking)
        $testedModels = $requirements['tested_models'] ?? [];
        if (! empty($testedModels)) {
            $availableTestedModel = null;

            foreach ($testedModels as $modelStr) {
                [$provider] = explode('/', $modelStr, 2) + [1 => null];
                if (in_array($provider, $available)) {
                    $availableTestedModel = $modelStr;
                    break;
                }
            }

            if ($availableTestedModel) {
                $suggestedModel = $availableTestedModel;
            } else {
                $warnings[] = 'No tested models available. Skill may behave unexpectedly.';
            }
        }

        // Check structured output requirement
        if (! empty($requirements['requires_structured_output'])) {
            $structuredOutputProviders = ['anthropic', 'openai'];
            $hasStructuredOutput = (bool) array_intersect($structuredOutputProviders, $available);
            if (! $hasStructuredOutput) {
                $warnings[] = 'Skill requires structured output support (Anthropic or OpenAI).';
            }
        }

        return [
            'compatible' => true,
            'warnings' => $warnings,
            'suggested_model' => $suggestedModel,
        ];
    }

    /**
     * Throw if incompatible.
     *
     * @throws SkillProviderIncompatibleException
     */
    public function assertCompatible(Skill $skill, Team $team): void
    {
        $requirements = $skill->provider_requirements ?? [];

        if (empty($requirements)) {
            return;
        }

        $available = $this->getAvailableProviders($team);
        $requiredProviders = $requirements['required_providers'] ?? [];

        $missing = array_filter($requiredProviders, fn ($p) => ! in_array($p, $available));

        if (! empty($missing)) {
            throw new SkillProviderIncompatibleException(
                array_values($missing),
                $available,
                $skill->name,
            );
        }
    }

    /**
     * Get list of provider keys available for a team (BYOK + platform config).
     */
    public function getAvailableProviders(Team $team): array
    {
        $providers = [];

        // Platform-level providers (env keys)
        $platformMap = [
            'anthropic' => 'ANTHROPIC_API_KEY',
            'openai' => 'OPENAI_API_KEY',
            'google' => 'GOOGLE_AI_API_KEY',
        ];

        foreach ($platformMap as $provider => $envKey) {
            if (config("services.{$provider}.api_key") || env($envKey)) {
                $providers[] = $provider;
            }
        }

        // Team BYOK credentials
        $byok = $team->providerCredentials()
            ->where('is_active', true)
            ->pluck('provider')
            ->toArray();

        return array_unique(array_merge($providers, $byok));
    }
}
