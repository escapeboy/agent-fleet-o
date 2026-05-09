<?php

declare(strict_types=1);

namespace App\Domain\Release\Crypto\Actions;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use App\Domain\Release\Crypto\Services\CanonicalizeManifest;
use App\Domain\Release\Models\Release;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Signs a release with the team's active Ed25519 key. If a grace-period key
 * exists, ALSO produces a dual-signature stored in metadata.dual_signatures[].
 *
 * The primary signature is stored on the Release row (signature, signing_key_id,
 * signed_at). Dual-sigs from grace keys are appended to the metadata JSONB column
 * so verifiers can fall back if the primary key is revoked mid-flight.
 *
 * Idempotent: re-signing a release that already has a signature is a no-op.
 */
class SignReleaseAction
{
    public function __construct(
        private readonly CanonicalizeManifest $canonicalizer,
    ) {}

    /**
     * @throws InvalidArgumentException when no active key exists for the team
     */
    public function execute(Release $release): Release
    {
        if ($release->signature !== null && $release->signing_key_id !== null) {
            return $release;
        }

        $manifest = $this->canonicalizer->canonicalize($release);

        return DB::transaction(function () use ($release, $manifest) {
            $activeKey = ReleaseSigningKey::where('team_id', $release->team_id)
                ->where('status', SigningKeyStatus::Active->value)
                ->lockForUpdate()
                ->first();

            if (! $activeKey) {
                throw new InvalidArgumentException(
                    "Cannot sign release [{$release->id}] — team [{$release->team_id}] has no active signing key. Generate one first.",
                );
            }

            $primarySig = $this->signWith($activeKey, $manifest);

            // Dual-sign with grace keys if any (90d rotation window).
            $graceKeys = ReleaseSigningKey::where('team_id', $release->team_id)
                ->where('status', SigningKeyStatus::Grace->value)
                ->where(function ($q) {
                    $q->whereNull('grace_expires_at')->orWhere('grace_expires_at', '>', now());
                })
                ->get();

            $dualSignatures = $graceKeys->map(fn (ReleaseSigningKey $k) => [
                'kid' => $k->id,
                'signature' => $this->signWith($k, $manifest),
            ])->all();

            $metadata = $release->metadata ?? [];
            if (! empty($dualSignatures)) {
                $metadata['dual_signatures'] = $dualSignatures;
            }

            $release->update([
                'signature' => $primarySig,
                'signing_key_id' => $activeKey->id,
                'signed_at' => now(),
                'metadata' => $metadata,
            ]);

            return $release->refresh();
        });
    }

    private function signWith(ReleaseSigningKey $key, string $manifest): string
    {
        $secretKey = base64_decode($key->secret_data);
        $signature = sodium_crypto_sign_detached($manifest, $secretKey);
        sodium_memzero($secretKey);

        return base64_encode($signature);
    }
}
