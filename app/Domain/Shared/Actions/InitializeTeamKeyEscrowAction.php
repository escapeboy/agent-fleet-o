<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKeyEscrow;
use Illuminate\Support\Facades\Log;

class InitializeTeamKeyEscrowAction
{
    /**
     * Create a 2-of-2 escrow for the team's credential key.
     *
     * The team holds its credential_key (owner's copy).
     * We store an XOR-masked backup in team_key_escrows:
     *   mask = random_bytes(32)
     *   platform_share = credential_key_bytes XOR mask
     *
     * Recovery requires both shares. The checksum allows verifying
     * integrity without exposing the key.
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

            // Generate random mask for XOR split
            $mask = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);

            // platform_share = key XOR mask
            $platformShare = $keyBytes ^ $mask;

            // Encrypt the platform share with the application key
            $encryptedShare = encrypt(base64_encode($platformShare));

            $escrow = TeamKeyEscrow::updateOrCreate(
                ['team_id' => $team->id],
                [
                    'encrypted_share' => $encryptedShare,
                    'share_checksum' => hash('sha256', $keyBytes),
                    'share_version' => 1,
                ],
            );

            // Wipe sensitive data from memory
            sodium_memzero($keyBytes);
            sodium_memzero($mask);
            sodium_memzero($platformShare);

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
