<?php

namespace App\Domain\Shared\Actions;

use App\Domain\Shared\Enums\KmsProvider;
use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Encryption\Kms\KmsWrapperService;

class TestKmsConnectivityAction
{
    public function __construct(
        private readonly KmsWrapperService $kmsService,
    ) {}

    /**
     * Test KMS connectivity using a temporary or existing config.
     *
     * @return array{success: bool, message: string}
     */
    public function execute(string $teamId, KmsProvider $provider, array $credentials): array
    {
        // Build a transient config for testing
        $config = new TeamKmsConfig([
            'team_id' => $teamId,
            'provider' => $provider,
            'credentials' => $credentials,
            'external_id' => $credentials['external_id'] ?? "fleetq-{$teamId}",
        ]);

        try {
            $result = $this->kmsService->testConnection($config);

            CredentialEncryption::logAccess(
                $teamId, 'team_kms_config', $teamId,
                'kms.test_succeeded',
                extra: ['provider' => $provider->value],
            );

            return [
                'success' => $result,
                'message' => $result
                    ? 'Connection successful. Permissions verified.'
                    : 'Connection test failed: round-trip verification mismatch.',
            ];
        } catch (\Throwable $e) {
            CredentialEncryption::logAccess(
                $teamId, 'team_kms_config', $teamId,
                'kms.test_failed',
                extra: ['provider' => $provider->value, 'error' => $e->getMessage()],
            );

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
