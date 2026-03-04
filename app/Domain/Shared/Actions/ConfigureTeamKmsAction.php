<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Enums\KmsConfigStatus;
use App\Domain\Shared\Enums\KmsProvider;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Encryption\Kms\KmsWrapperService;
use Illuminate\Support\Facades\DB;

class ConfigureTeamKmsAction
{
    public function __construct(
        private readonly KmsWrapperService $kmsService,
        private readonly TestKmsConnectivityAction $testAction,
    ) {}

    /**
     * Configure and enable KMS for a team.
     *
     * @return array{success: bool, message: string}
     */
    public function execute(
        string $teamId,
        KmsProvider $provider,
        array $credentials,
        string $keyIdentifier,
    ): array {
        // 1. Test connectivity first
        $externalId = "fleetq-{$teamId}";
        $testCredentials = $credentials;
        $testCredentials['external_id'] = $externalId;

        $testResult = $this->testAction->execute($teamId, $provider, $testCredentials);
        if (! $testResult['success']) {
            return $testResult;
        }

        // 2. Load existing plaintext DEK
        $team = Team::withoutGlobalScopes()->findOrFail($teamId);
        $plaintextDek = base64_decode($team->credential_key);

        if (strlen($plaintextDek) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return [
                'success' => false,
                'message' => 'Team does not have a valid credential key. Contact support.',
            ];
        }

        return DB::transaction(function () use ($teamId, $provider, $credentials, $keyIdentifier, $externalId, $plaintextDek) {
            // 3. Build config for wrapping
            $kmsConfig = TeamKmsConfig::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $teamId],
                [
                    'provider' => $provider,
                    'credentials' => $credentials,
                    'key_identifier' => $keyIdentifier,
                    'external_id' => $externalId,
                    'status' => KmsConfigStatus::Testing,
                    'wrapped_dek' => '', // placeholder
                ],
            );

            // 4. Wrap DEK with customer's KMS
            $wrappedDek = $this->kmsService->wrapDek($plaintextDek, $kmsConfig);

            // Wipe plaintext DEK from memory
            sodium_memzero($plaintextDek);

            // 5. Save wrapped DEK and activate
            $kmsConfig->update([
                'wrapped_dek' => $wrappedDek,
                'status' => KmsConfigStatus::Active,
                'dek_wrapped_at' => now(),
                'last_tested_at' => now(),
            ]);

            // 6. Flush Redis DEK cache
            $this->kmsService->flushCache($teamId);

            // 7. Audit log
            CredentialEncryption::logAccess(
                $teamId, 'team_kms_config', $kmsConfig->id,
                'kms.configured',
                extra: ['provider' => $kmsConfig->provider->value],
            );

            return [
                'success' => true,
                'message' => "KMS configured successfully with {$kmsConfig->provider->label()}.",
            ];
        });
    }
}
