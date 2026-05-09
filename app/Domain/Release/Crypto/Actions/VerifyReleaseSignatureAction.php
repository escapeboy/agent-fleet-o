<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Actions;

use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use App\Domain\Release\Crypto\Services\CanonicalizeManifest;
use App\Domain\Release\Models\Release;

/**
 * Verifies a release's primary signature, falling back to dual-signatures
 * stored under metadata.dual_signatures[] when the primary key is revoked.
 *
 * Returns one of:
 *   ['status' => 'verified',           'kid' => string, 'via' => 'primary']
 *   ['status' => 'verified_grace',     'kid' => string, 'via' => 'dual']
 *   ['status' => 'unsigned',           'kid' => null,   'via' => null]
 *   ['status' => 'unverified',         'kid' => string, 'via' => null]
 *   ['status' => 'revoked',            'kid' => string, 'via' => null]
 */
class VerifyReleaseSignatureAction
{
    public function __construct(
        private readonly CanonicalizeManifest $canonicalizer,
    ) {}

    /**
     * @return array{status: string, kid: ?string, via: ?string}
     */
    public function execute(Release $release): array
    {
        if ($release->signature === null || $release->signing_key_id === null) {
            return ['status' => 'unsigned', 'kid' => null, 'via' => null];
        }

        $manifest = $this->canonicalizer->canonicalize($release);
        $primaryKey = ReleaseSigningKey::withoutGlobalScopes()->find($release->signing_key_id);

        if ($primaryKey && ! $primaryKey->isRevoked() && $this->verifyWith($primaryKey, $release->signature, $manifest)) {
            return ['status' => 'verified', 'kid' => $primaryKey->id, 'via' => 'primary'];
        }

        // Try dual-signatures from grace keys if primary is revoked or fails verification
        $duals = $release->metadata['dual_signatures'] ?? [];
        foreach ($duals as $dual) {
            $dualKey = ReleaseSigningKey::withoutGlobalScopes()->find($dual['kid'] ?? null);
            if (! $dualKey || $dualKey->isRevoked()) {
                continue;
            }

            if ($this->verifyWith($dualKey, $dual['signature'] ?? '', $manifest)) {
                return ['status' => 'verified_grace', 'kid' => $dualKey->id, 'via' => 'dual'];
            }
        }

        if ($primaryKey?->isRevoked()) {
            return ['status' => 'revoked', 'kid' => $primaryKey->id, 'via' => null];
        }

        return ['status' => 'unverified', 'kid' => $primaryKey?->id, 'via' => null];
    }

    private function verifyWith(ReleaseSigningKey $key, string $signatureB64, string $manifest): bool
    {
        $publicKey = base64_decode($key->public_key, true);
        $signature = base64_decode($signatureB64, true);

        if ($publicKey === false || $signature === false) {
            return false;
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $manifest, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }
}
