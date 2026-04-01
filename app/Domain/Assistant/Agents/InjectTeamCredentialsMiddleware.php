<?php

namespace App\Domain\Assistant\Agents;

use App\Domain\Shared\Models\TeamProviderCredential;
use Closure;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * Agent middleware that injects team BYOK API credentials into the AI config
 * before the prompt is sent to the provider. Restores original config after
 * to prevent credential leaking between Horizon jobs on the same worker.
 */
class InjectTeamCredentialsMiddleware
{
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        $teamId = Auth::user()?->current_team_id;
        $providerName = $prompt->provider->name();
        $configKey = "ai.providers.{$providerName}.key";
        $originalKey = config($configKey);

        if ($teamId) {
            $this->applyTeamCredentials($teamId, $providerName, $configKey);
        }

        try {
            return $next($prompt);
        } finally {
            // Restore original config to prevent leaking team's API key
            // to the next job on this Horizon worker
            config([$configKey => $originalKey]);
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
