<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKeyEscrow;
use Illuminate\Support\Facades\Log;

class InitializeTeamKeyEscrowAction
{
    /**
     * Store an encrypted backup of the team's credential key in team_key_escrows.
     *
     * The key is encrypted with the application APP_KEY via Laravel's encrypt(),
     * giving AES-256-CBC + HMAC envelope encryption at rest.
     * The checksum (HMAC-SHA-256 keyed on APP_KEY) allows integrity verification
     * without exposing the raw key, and is not brute-forceable without APP_KEY.
     */
    public function execute(Team $team): ?TeamKeyEscrow
    {
        if (! $team->credential_key) {
            return null;
        }

        try {
            $keyBytes = base64_decode($team->credential_key);

            if (strlen($keyBytes) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return null;
            }

            $encryptedShare = encrypt($team->credential_key);
            $checksum = hash_hmac('sha256', $keyBytes, config('app.key'));

            $escrow = TeamKeyEscrow::updateOrCreate(
                ['team_id' => $team->id],
                [
                    'encrypted_share' => $encryptedShare,
                    'share_checksum' => $checksum,
                    'share_version' => 1,
                ],
            );

            sodium_memzero($keyBytes);

            return $escrow;
        } catch (\Throwable $e) {
            Log::error('Failed to initialize team key escrow', [
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
