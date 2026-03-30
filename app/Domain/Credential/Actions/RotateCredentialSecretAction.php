<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialVersion;

class RotateCredentialSecretAction
{
    /**
     * Snapshot the current secret_data into credential_versions, then apply the new secret.
     *
     * @param  array<string, mixed>  $newSecretData  New secret key-value pairs.
     * @param  string|null  $note  Optional human-readable reason for the rotation.
     * @param  string|null  $rotatedBy  User UUID who initiated the rotation (for audit).
     */
    public function execute(
        Credential $credential,
        array $newSecretData,
        ?string $note = null,
        ?string $rotatedBy = null,
    ): Credential {
        // Determine the next version number for this credential.
        $nextVersion = CredentialVersion::withoutGlobalScopes()
            ->where('credential_id', $credential->id)
            ->max('version_number') ?? 0;
        $nextVersion++;

        // Snapshot current secret_data before overwriting.
        CredentialVersion::withoutGlobalScopes()->create([
            'credential_id' => $credential->id,
            'team_id' => $credential->team_id,
            'secret_data' => $credential->secret_data,
            'version_number' => $nextVersion,
            'note' => $note,
            'created_by' => $rotatedBy,
            'created_at' => now(),
        ]);

        $credential->update([
            'secret_data' => $newSecretData,
            'last_rotated_at' => now(),
        ]);

        return $credential->fresh();
    }
}
