<?php

namespace App\Infrastructure\Encryption;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Enums\KmsConfigStatus;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Models\TeamKmsConfig;
use App\Infrastructure\Encryption\Kms\Exceptions\KmsUnavailableException;
use App\Infrastructure\Encryption\Kms\KmsWrapperService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

class CredentialEncryption
{
    private const VERSION = 2;

    /** @var array<string, string> In-memory cache of decrypted team keys (per-request only) */
    private array $keyCache = [];

    /**
     * Encrypt data using the team's dedicated encryption key.
     * Falls back to APP_KEY if no team key exists.
     */
    public function encrypt(mixed $data, ?string $teamId): string
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $teamKey = $teamId ? $this->resolveTeamKey($teamId) : null;

        if ($teamKey) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($json, $nonce, $teamKey);

            $envelope = json_encode([
                'v' => self::VERSION,
                'n' => base64_encode($nonce),
                'c' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR);

            // Wipe sensitive data from memory
            sodium_memzero($json);
            sodium_memzero($teamKey);

            return base64_encode($envelope);
        }

        // Fallback to Laravel's default encrypter (APP_KEY)
        return app('encrypter')->encrypt($json, false);
    }

    /**
     * Decrypt data, auto-detecting v2 (team key) vs v1 (APP_KEY) format.
     */
    public function decrypt(string $stored, ?string $teamId): mixed
    {
        // Try v2 envelope format
        $decoded = base64_decode($stored, true);
        if ($decoded !== false) {
            $envelope = @json_decode($decoded, true);
            if (is_array($envelope) && ($envelope['v'] ?? 0) === self::VERSION) {
                return $this->decryptV2($envelope, $teamId);
            }
        }

        // Fallback to Laravel encrypter (v1 / legacy format)
        $json = app('encrypter')->decrypt($stored, false);

        return json_decode($json, true);
    }

    /**
     * Decrypt v2 envelope format using team key.
     */
    private function decryptV2(array $envelope, ?string $teamId): mixed
    {
        $teamKey = $teamId ? $this->resolveTeamKey($teamId) : null;

        if (! $teamKey) {
            throw new \RuntimeException(
                'Cannot decrypt v2 credential: no team key available.',
            );
        }

        $nonce = base64_decode($envelope['n']);
        $ciphertext = base64_decode($envelope['c']);

        $json = sodium_crypto_secretbox_open($ciphertext, $nonce, $teamKey);

        if ($json === false) {
            sodium_memzero($teamKey);

            throw new \RuntimeException(
                'Credential decryption failed: invalid team key or corrupted data.',
            );
        }

        $result = json_decode($json, true);

        // Wipe sensitive data from memory
        sodium_memzero($json);
        sodium_memzero($teamKey);

        return $result;
    }

    /**
     * Resolve the decrypted team key, with per-request caching.
     *
     * Resolution order:
     * 1. In-memory cache (per-request)
     * 2. KMS config (if active) → unwrap via KmsWrapperService
     * 3. Team.credential_key (APP_KEY-wrapped DEK)
     * 4. null (APP_KEY direct fallback for v1 legacy)
     *
     * SECURITY: When KMS is configured and active, NEVER fall back to APP_KEY.
     * Revoking KMS access must revoke data access.
     */
    private function resolveTeamKey(?string $teamId): ?string
    {
        if (! $teamId) {
            return null;
        }

        if (isset($this->keyCache[$teamId])) {
            return $this->keyCache[$teamId];
        }

        // Check for KMS configuration
        $kmsConfig = TeamKmsConfig::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->first();

        if ($kmsConfig && $kmsConfig->status === KmsConfigStatus::Active) {
            // KMS is active — unwrap DEK via KMS (no APP_KEY fallback)
            $kmsService = app(KmsWrapperService::class);
            $key = $kmsService->unwrapDek($kmsConfig);

            $this->keyCache[$teamId] = $key;

            return $key;
        }

        if ($kmsConfig && $kmsConfig->status === KmsConfigStatus::Error) {
            // KMS is in error state — throw, do NOT fall back
            throw new KmsUnavailableException(
                $kmsConfig->provider->value,
                'KMS is in error state. Check your KMS configuration in Team Settings.',
            );
        }

        // No KMS config (or disabled) — use APP_KEY-wrapped DEK from Team model
        $team = Team::withoutGlobalScopes()->find($teamId);

        if (! $team) {
            return null;
        }

        try {
            $rawKey = $team->credential_key;
        } catch (DecryptException) {
            // credential_key was stored with a different APP_KEY — fall back to APP_KEY direct encryption
            return null;
        }

        if (! $rawKey) {
            return null;
        }

        $key = base64_decode($rawKey);

        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return null;
        }

        $this->keyCache[$teamId] = $key;

        return $key;
    }

    /**
     * Generate a new random team encryption key.
     * Returns a base64-encoded 32-byte key.
     */
    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    /**
     * Log a credential access event to the audit trail.
     */
    public static function logAccess(
        ?string $teamId,
        string $subjectType,
        string $subjectId,
        string $purpose = 'runtime_access',
        ?string $userId = null,
        array $extra = [],
    ): void {
        try {
            AuditEntry::create([
                'team_id' => $teamId,
                'user_id' => $userId ?? auth()->id(),
                'event' => 'credential.accessed',
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'properties' => array_filter([
                    'purpose' => $purpose,
                    ...$extra,
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging break credential access
        }
    }

    /**
     * Clear the in-memory key cache (useful after processing).
     */
    public function clearKeyCache(): void
    {
        foreach ($this->keyCache as &$key) {
            if (is_string($key)) {
                sodium_memzero($key);
            }
        }
        $this->keyCache = [];
    }

    public function __destruct()
    {
        $this->clearKeyCache();
    }
}
