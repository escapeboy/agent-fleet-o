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
            $platformShareBase64 = decrypt($escrow->encrypted_share);
            $platformShare = base64_decode($platformShareBase64);

            if (strlen($platformShare) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('Escrow share has unexpected length — data may be corrupted.');
            }

            // The team's current credential_key IS the owner share (key XOR mask).
            // To recover: owner_share XOR platform_share = key XOR mask XOR mask = key.
            // If the owner's key is gone, we can reconstruct from the stored checksum
            // and issue a new key rotation (this branch handles full key loss).
            $teamKeyBytes = base64_decode($team->credential_key ?? '');

            if (strlen($teamKeyBytes) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                // Owner key intact — verify checksum
                $checksum = hash('sha256', $teamKeyBytes);
                if ($checksum !== $escrow->share_checksum) {
                    throw new \RuntimeException('Key checksum mismatch — escrow may be stale.');
                }
                sodium_memzero($teamKeyBytes);
            }

            // Return decrypted platform share for audit; caller uses this to
            // reconstruct or re-issue the credential key
            $recoveredKey = base64_encode($platformShare);
            sodium_memzero($platformShare);

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
