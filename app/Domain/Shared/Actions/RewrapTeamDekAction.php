<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Encryption\Kms\KmsWrapperService;
use Illuminate\Support\Facades\DB;

class RewrapTeamDekAction
{
    public function __construct(
        private readonly KmsWrapperService $kmsService,
    ) {}

    /**
     * Re-wrap the team's DEK with the current CMK version.
     * Useful after key rotation in the customer's KMS.
     *
     * @return array{success: bool, message: string}
     */
    public function execute(string $teamId): array
    {
        $kmsConfig = TeamKmsConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if (! $kmsConfig) {
            return ['success' => false, 'message' => 'No KMS configuration found for this team.'];
        }

        return DB::transaction(function () use ($kmsConfig, $teamId) {
            // 1. Unwrap current DEK
            $dek = $this->kmsService->unwrapDek($kmsConfig);

            // 2. Re-wrap with current CMK version
            $newWrappedDek = $this->kmsService->wrapDek($dek, $kmsConfig);

            // 3. Wipe plaintext DEK
            sodium_memzero($dek);

            // 4. Update wrapped DEK
            $kmsConfig->update([
                'wrapped_dek' => $newWrappedDek,
                'dek_wrapped_at' => now(),
            ]);

            // 5. Flush cache so next access uses new wrapped DEK
            $this->kmsService->flushCache($teamId);

            // 6. Audit log
            CredentialEncryption::logAccess(
                $teamId, 'team_kms_config', $kmsConfig->id,
                'kms.dek_rewrapped',
                extra: ['provider' => $kmsConfig->provider->value],
            );

            return [
                'success' => true,
                'message' => 'DEK re-wrapped successfully with current CMK version.',
            ];
        });
    }
}
