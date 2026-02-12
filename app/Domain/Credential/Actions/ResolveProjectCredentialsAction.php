<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Project\Models\Project;

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
            ->where('status', CredentialStatus::Active)
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
}
