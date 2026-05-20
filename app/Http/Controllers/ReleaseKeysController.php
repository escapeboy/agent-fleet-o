<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Release\Crypto\Enums\SigningKeyStatus;
use App\Domain\Release\Crypto\Models\ReleaseSigningKey;
use Illuminate\Http\JsonResponse;

/**
 * Public JWKS-style endpoint serving release signing public keys.
 *
 * Active + grace keys are returned (revoked excluded). Verifiers use the kid
 * from a release to find the matching public key here, then verify the
 * Ed25519 signature against the canonical manifest.
 *
 * No auth — public discovery, throttled at the route layer.
 */
class ReleaseKeysController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $keys = ReleaseSigningKey::withoutGlobalScopes()
            ->whereIn('status', [SigningKeyStatus::Active->value, SigningKeyStatus::Grace->value])
            ->where(function ($q) {
                $q->whereNull('grace_expires_at')->orWhere('grace_expires_at', '>', now());
            })
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'keys' => $keys->map(fn (ReleaseSigningKey $k) => [
                'kid' => $k->id,
                'kty' => 'OKP',           // RFC 8037 — Octet Key Pair
                'crv' => 'Ed25519',
                'alg' => 'EdDSA',
                'use' => 'sig',
                'x' => $k->public_key,    // base64-encoded raw 32-byte pubkey (RFC 7517 §4.3 with our base64-not-base64url for simplicity)
                'team_id' => $k->team_id,
                'status' => $k->status->value,
                'grace_expires_at' => $k->grace_expires_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
