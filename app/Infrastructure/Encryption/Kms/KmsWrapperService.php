<?php

namespace App\Infrastructure\Encryption\Kms;

use App\Domain\Shared\Enums\KmsConfigStatus;
use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\CredentialEncryption;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KmsWrapperService
{
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    private const CACHE_PREFIX = 'kms_dek:';

    /** @var array<string, string> In-memory per-request cache */
    private array $dekCache = [];

    public function __construct(
        private readonly KmsWrapperFactory $factory,
    ) {}

    /**
     * Unwrap (decrypt) the team's DEK using their KMS provider.
     * Returns raw binary DEK (32 bytes).
     *
     * Uses three-layer cache: in-memory → Redis (APP_KEY encrypted) → KMS call.
     */
    public function unwrapDek(TeamKmsConfig $kmsConfig): string
    {
        $teamId = $kmsConfig->team_id;

        // Layer 1: in-memory per-request cache
        if (isset($this->dekCache[$teamId])) {
            return $this->dekCache[$teamId];
        }

        // Layer 2: Redis cache (DEK encrypted with APP_KEY)
        $cacheKey = self::CACHE_PREFIX . $teamId;
        $cached = Cache::get($cacheKey);
        if ($cached) {
            try {
                $dek = app('encrypter')->decrypt($cached, false);
                $this->dekCache[$teamId] = $dek;

                return $dek;
            } catch (\Throwable) {
                // Cache corrupted, fall through to KMS
                Cache::forget($cacheKey);
            }
        }

        // Layer 3: KMS API call
        $wrapper = $this->factory->make($kmsConfig->provider);
        $config = $this->buildProviderConfig($kmsConfig);

        try {
            $dek = $wrapper->unwrap($kmsConfig->wrapped_dek, $config);
        } catch (\Throwable $e) {
            Log::error('KMS unwrap failed', [
                'team_id' => $teamId,
                'provider' => $kmsConfig->provider->value,
                'error' => $e->getMessage(),
            ]);

            // Mark config as error
            $kmsConfig->update(['status' => KmsConfigStatus::Error]);

            CredentialEncryption::logAccess(
                $teamId, 'team_kms_config', $kmsConfig->id,
                'kms.unwrap_failed',
                extra: ['provider' => $kmsConfig->provider->value, 'error' => $e->getMessage()],
            );

            throw new KmsUnavailableException(
                $kmsConfig->provider->value,
                $e->getMessage(),
                $e,
            );
        }

        // Cache the unwrapped DEK (encrypted with APP_KEY)
        $encryptedDek = app('encrypter')->encrypt($dek, false);
        Cache::put($cacheKey, $encryptedDek, self::CACHE_TTL_SECONDS);
        $this->dekCache[$teamId] = $dek;

        // Update last_used_at (fire-and-forget, don't block)
        $kmsConfig->update(['last_used_at' => now()]);

        return $dek;
    }

    /**
     * Wrap (encrypt) a plaintext DEK with the customer's KMS key.
     */
    public function wrapDek(string $plaintextDek, TeamKmsConfig $kmsConfig): string
    {
        $wrapper = $this->factory->make($kmsConfig->provider);
        $config = $this->buildProviderConfig($kmsConfig);

        return $wrapper->wrap($plaintextDek, $config);
    }

    /**
     * Test KMS connectivity for the given config.
     */
    public function testConnection(TeamKmsConfig $kmsConfig): bool
    {
        $wrapper = $this->factory->make($kmsConfig->provider);
        $config = $this->buildProviderConfig($kmsConfig);

        return $wrapper->test($config);
    }

    /**
     * Flush the cached DEK for a team.
     */
    public function flushCache(string $teamId): void
    {
        Cache::forget(self::CACHE_PREFIX . $teamId);

        if (isset($this->dekCache[$teamId])) {
            if (is_string($this->dekCache[$teamId])) {
                sodium_memzero($this->dekCache[$teamId]);
            }
            unset($this->dekCache[$teamId]);
        }
    }

    /**
     * Build the provider-specific config array from the KMS config model.
     */
    private function buildProviderConfig(TeamKmsConfig $kmsConfig): array
    {
        $credentials = $kmsConfig->credentials;

        // Add external_id for AWS
        if ($kmsConfig->external_id) {
            $credentials['external_id'] = $kmsConfig->external_id;
        }

        return $credentials;
    }

    public function __destruct()
    {
        foreach ($this->dekCache as &$dek) {
            if (is_string($dek)) {
                sodium_memzero($dek);
            }
        }
        $this->dekCache = [];
    }
}
