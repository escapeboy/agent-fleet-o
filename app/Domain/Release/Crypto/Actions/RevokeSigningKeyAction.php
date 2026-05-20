<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Actions;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;

/**
 * Immediately revokes a signing key — for compromise scenarios.
 *
 * Unlike rotation, no grace period is granted. Releases signed by a revoked
 * key will fail verification with status = "revoked".
 *
 * Idempotent: revoking an already-revoked key is a no-op.
 */
class RevokeSigningKeyAction
{
    public function execute(ReleaseSigningKey $key): ReleaseSigningKey
    {
        if ($key->isRevoked()) {
            return $key;
        }

        $key->update([
            'status' => SigningKeyStatus::Revoked,
            'revoked_at' => now(),
            'grace_expires_at' => null,
        ]);

        return $key->refresh();
    }
}
