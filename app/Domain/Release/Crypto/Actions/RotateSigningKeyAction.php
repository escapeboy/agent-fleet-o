<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Actions;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use Illuminate\Support\Facades\DB;

/**
 * Rotates a team's signing key:
 *   - existing active key transitions to GRACE (90-day window for dual-sig)
 *   - new active key is generated
 *
 * Manually-triggered. For compromise scenarios, use RevokeSigningKeyAction
 * which immediately invalidates the previous key without grace period.
 */
class RotateSigningKeyAction
{
    public function __construct(
        private readonly GenerateSigningKeyAction $generator,
    ) {}

    public function execute(string $teamId): ReleaseSigningKey
    {
        return DB::transaction(function () use ($teamId) {
            $current = ReleaseSigningKey::where('team_id', $teamId)
                ->where('status', SigningKeyStatus::Active->value)
                ->first();

            if ($current) {
                $current->update([
                    'status' => SigningKeyStatus::Grace,
                    'rotated_at' => now(),
                    'grace_expires_at' => now()->addDays(90),
                ]);
            }

            // Generate must explicitly bypass the idempotency guard since the
            // active key now is grace; reuse the static helper directly.
            $keypair = sodium_crypto_sign_keypair();

            return ReleaseSigningKey::create([
                'team_id' => $teamId,
                'public_key' => base64_encode(sodium_crypto_sign_publickey($keypair)),
                'secret_data' => base64_encode(sodium_crypto_sign_secretkey($keypair)),
                'status' => SigningKeyStatus::Active,
            ]);
        });
    }
}
