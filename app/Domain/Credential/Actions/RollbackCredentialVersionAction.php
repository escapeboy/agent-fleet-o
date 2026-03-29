<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Credential\Models\CredentialVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RollbackCredentialVersionAction
{
    /**
     * Restore a credential's secret_data to a previous version.
     *
     * Append-only history: rollback creates a new snapshot of the current value
     * before restoring the target version's secret_data. This preserves a complete
     * audit trail — no history is ever deleted.
     *
     * @param  string|null  $rolledBackBy  User UUID who initiated the rollback.
     *
     * @throws ModelNotFoundException
     */
    public function execute(
        Credential $credential,
        CredentialVersion $version,
        ?string $rolledBackBy = null,
    ): Credential {
        $note = "Rollback to version {$version->version_number}";

        // Re-use RotateCredentialSecretAction to snapshot current state and write the
        // restored secret in one atomic operation, preserving the version trail.
        return app(RotateCredentialSecretAction::class)->execute(
            $credential,
            $version->secret_data,
            $note,
            $rolledBackBy,
        );
    }
}
