<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKeyEscrow;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

class RecoverTeamKeyAction
{
    /**
     * Recover a team's credential key using the platform escrow share.
     *
     * This is a super-admin-only operation. Returns the raw base64-encoded
     * credential_key for the caller to re-assign to the team record.
     *
     * @throws \RuntimeException if escrow not found or decryption fails
     */
    public function execute(Team $team): string
    {
        $escrow = TeamKeyEscrow::where('team_id', $team->id)->first();

        if (! $escrow) {
            throw new \RuntimeException("No key escrow found for team {$team->id}.");
        }

        try {
            $recoveredKeyBase64 = decrypt($escrow->encrypted_share);
            $recoveredKeyBytes = base64_decode($recoveredKeyBase64);

            if (strlen($recoveredKeyBytes) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('Escrow payload has unexpected length — data may be corrupted.');
            }

            // Verify integrity using the stored HMAC-SHA-256 checksum
            $expectedChecksum = hash_hmac('sha256', $recoveredKeyBytes, config('app.key'));
            if (! hash_equals($expectedChecksum, $escrow->share_checksum)) {
                throw new \RuntimeException('Key checksum mismatch — escrow may be stale or tampered.');
            }

            $recoveredKey = $recoveredKeyBase64;
            sodium_memzero($recoveredKeyBytes);

            Log::warning('Team key escrow recovery performed', [
                'team_id' => $team->id,
                'share_version' => $escrow->share_version,
            ]);

            return $recoveredKey;
        } catch (DecryptException $e) {
            throw new \RuntimeException('Failed to decrypt escrow share: application key may have changed.');
        }
    }
}
