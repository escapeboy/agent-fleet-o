<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Models\Project;
use Illuminate\Support\Facades\Log;

class ResolveProjectCredentialsAction
{
    /**
     * Returns credential metadata (id, name, type) for the agent's system prompt.
     * Does NOT return secret_data — agents request secrets on demand.
     */
    public function execute(?Project $project = null): array
    {
        if (! $project || empty($project->allowed_credential_ids)) {
            return [];
        }

        return Credential::withoutGlobalScopes()
            ->whereIn('id', $project->allowed_credential_ids)
            ->where('status', CredentialStatus::Active) // excludes pending_review and disabled
            ->get()
            ->reject(fn (Credential $c) => $c->isExpired())
            ->map(fn (Credential $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->credential_type->value,
                'description' => $c->description,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Resolve all credentials attached to an agent's tools as environment variables.
     *
     * Produces: CRED_{UPPER_NAME}_{KEY} = value
     * e.g. CRED_STRIPE_API_KEY = "sk_..."
     *
     * Used to inject credentials into bash tool subprocesses without exposing
     * them in LLM system prompts or assistant messages.
     *
     * @return array<string, string>
     */
    public function resolveAsEnvMap(string $agentId): array
    {
        $agent = Agent::withoutGlobalScopes()->find($agentId);
        if (! $agent) {
            return [];
        }

        $credentialIds = $agent->tools()
            ->whereNotNull('credential_id')
            ->pluck('credential_id')
            ->unique()
            ->toArray();

        if (empty($credentialIds)) {
            return [];
        }

        $env = [];
        $credentials = Credential::withoutGlobalScopes()
            ->whereIn('id', $credentialIds)
            ->where('status', CredentialStatus::Active)
            ->get();

        foreach ($credentials as $credential) {
            $prefix = 'CRED_'.strtoupper(
                preg_replace('/[^A-Za-z0-9]+/', '_', $credential->name),
            );

            foreach ($credential->secret_data ?? [] as $key => $value) {
                $envKey = $prefix.'_'.strtoupper($key);

                if (array_key_exists($envKey, $env)) {
                    Log::warning('ResolveProjectCredentialsAction: env var collision — two credentials produce the same key; last write wins', [
                        'env_key' => $envKey,
                        'agent_id' => $agent->id,
                        'credential_id' => $credential->id,
                    ]);
                }

                $env[$envKey] = (string) $value;
            }
        }

        return $env;
    }
}
