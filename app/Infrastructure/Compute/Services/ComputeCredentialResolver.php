<?php

namespace App\Infrastructure\Compute\Services;

use App\Domain\Shared\Models\TeamProviderCredential;

/**
 * Resolves compute provider credentials from TeamProviderCredential.
 *
 * Extracted from the duplicated resolveApiKey() private methods in:
 *   - ExecuteRunPodSkillAction
 *   - ExecuteRunPodPodSkillAction
 */
class ComputeCredentialResolver
{
    /**
     * Resolve credentials for a provider. Returns null if none configured.
     */
    public function resolve(string $teamId, string $provider): ?array
    {
        $credential = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', $provider)
            ->where('is_active', true)
            ->first();

        $creds = $credential?->credentials;

        return is_array($creds) ? $creds : null;
    }

    /**
     * Resolve credentials or throw a user-friendly exception.
     *
     * @throws \RuntimeException
     */
    public function resolveOrFail(string $teamId, string $provider): array
    {
        $credentials = $this->resolve($teamId, $provider);

        if (! $credentials) {
            throw new \RuntimeException(
                "No active credentials found for compute provider '{$provider}'. "
                .'Configure your API key in Team Settings → Integrations.',
            );
        }

        return $credentials;
    }

    /**
     * Resolve just the API key from credentials.
     */
    public function resolveApiKey(string $teamId, string $provider): ?string
    {
        $credentials = $this->resolve($teamId, $provider);

        return $credentials['api_key'] ?? null;
    }
}
