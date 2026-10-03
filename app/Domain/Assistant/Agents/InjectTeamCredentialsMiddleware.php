<?php

namespace App\Domain\Assistant\Agents;

use App\Domain\Shared\Models\TeamProviderCredential;
use Closure;
use Laravel\Ai\AiManager;

/**
 * Agent middleware that injects team BYOK API credentials into the AI config
 * before the prompt is sent to the provider. Restores original config after
 * to prevent credential leaking between Horizon jobs on the same worker.
 */
class InjectTeamCredentialsMiddleware
{
    /**
     * Run a laravel/ai call with the team's own provider key.
     *
     * laravel/ai caches provider instances per process (MultipleInstanceManager)
     * and, since 1.0, resolves the provider before agent middleware runs (which
     * now wraps each generation step). So the key is set around the whole call,
     * and the cached instance is dropped before (to pick the team key up) and
     * after (so the next job on this worker never reuses it).
     */
    public function around(?string $teamId, string $providerName, Closure $callback): mixed
    {
        $configKey = "ai.providers.{$providerName}.key";
        $originalKey = config($configKey);
        $manager = app(AiManager::class);

        if ($teamId) {
            $this->applyTeamCredentials($teamId, $providerName, $configKey);
        }
        $manager->forgetInstance($providerName);

        try {
            return $callback();
        } finally {
            config([$configKey => $originalKey]);
            $manager->forgetInstance($providerName);
        }
    }

    private function applyTeamCredentials(string $teamId, string $providerName, string $configKey): void
    {
        $providerMapping = [
            'anthropic' => 'anthropic',
            'openai' => 'openai',
            'gemini' => 'google',
            'groq' => 'groq',
            'mistral' => 'mistral',
            'deepseek' => 'deepseek',
            'xai' => 'xai',
        ];

        $internalProvider = $providerMapping[$providerName] ?? $providerName;

        $credential = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', $internalProvider)
            ->first();

        if (! $credential) {
            return;
        }

        $credentials = $credential->credentials ?? [];
        $apiKey = $credentials['api_key'] ?? null;

        if ($apiKey) {
            config([$configKey => $apiKey]);
        }
    }
}
