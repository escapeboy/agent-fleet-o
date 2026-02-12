<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Enums\CredentialType;
use App\Domain\Credential\Models\Credential;
use Illuminate\Support\Str;

class CreateCredentialAction
{
    public function execute(
        string $teamId,
        string $name,
        CredentialType $credentialType,
        array $secretData,
        ?string $description = null,
        array $metadata = [],
        ?string $expiresAt = null,
    ): Credential {
        return Credential::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'credential_type' => $credentialType,
            'status' => CredentialStatus::Active,
            'secret_data' => $secretData,
            'metadata' => $metadata,
            'expires_at' => $expiresAt,
        ]);
    }
}
