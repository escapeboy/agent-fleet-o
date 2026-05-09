<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Actions;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use InvalidArgumentException;
use SodiumException;

/**
 * Generates a new Ed25519 signing keypair for a team.
 *
 * Idempotent on (team_id, status=active): if an active key already exists,
 * returns it. Use RotateSigningKeyAction to replace.
 */
class GenerateSigningKeyAction
{
    /**
     * @throws SodiumException when libsodium is unavailable
     */
    public function execute(string $teamId): ReleaseSigningKey
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new InvalidArgumentException('libsodium is not available — Ed25519 signing keys cannot be generated.');
        }

        $existing = ReleaseSigningKey::where('team_id', $teamId)
            ->where('status', SigningKeyStatus::Active->value)
            ->first();

        if ($existing) {
            return $existing;
        }

        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        return ReleaseSigningKey::create([
            'team_id' => $teamId,
            'public_key' => base64_encode($publicKey),
            'secret_data' => base64_encode($secretKey),
            'status' => SigningKeyStatus::Active,
        ]);
    }
}
