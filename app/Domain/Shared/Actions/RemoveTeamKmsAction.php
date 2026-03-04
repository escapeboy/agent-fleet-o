<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Encryption\Kms\KmsWrapperService;
use Illuminate\Support\Facades\DB;

class RemoveTeamKmsAction
{
    public function __construct(
        private readonly KmsWrapperService $kmsService,
    ) {}

    /**
     * Remove KMS configuration, reverting to APP_KEY-wrapped DEK.
     *
     * @return array{success: bool, message: string}
     */
    public function execute(string $teamId, bool $force = false): array
    {
        $kmsConfig = TeamKmsConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if (! $kmsConfig) {
            return ['success' => false, 'message' => 'No KMS configuration found for this team.'];
        }

        return DB::transaction(function () use ($kmsConfig, $teamId, $force) {
            // Try to unwrap DEK from KMS to verify consistency
            try {
                $dek = $this->kmsService->unwrapDek($kmsConfig);
                sodium_memzero($dek);
            } catch (\Throwable $e) {
                if (! $force) {
                    return [
                        'success' => false,
                        'message' => "Cannot reach KMS to verify DEK. Use force=true to remove anyway (credentials may become inaccessible). Error: {$e->getMessage()}",
                    ];
                }

                // Force removal — audit this explicitly
                CredentialEncryption::logAccess(
                    $teamId, 'team_kms_config', $kmsConfig->id,
                    'kms.force_removed',
                    extra: ['provider' => $kmsConfig->provider->value, 'error' => $e->getMessage()],
                );
            }

            // Delete KMS config
            $kmsConfig->delete();

            // Flush cached DEK
            $this->kmsService->flushCache($teamId);

            // Audit log
            CredentialEncryption::logAccess(
                $teamId, 'team_kms_config', $kmsConfig->id,
                $force ? 'kms.force_removed' : 'kms.removed',
                extra: ['provider' => $kmsConfig->provider->value],
            );

            return [
                'success' => true,
                'message' => 'KMS configuration removed. Credentials are now protected by platform encryption.',
            ];
        });
    }
}
