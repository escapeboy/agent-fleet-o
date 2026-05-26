<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Mints opaque, run-scoped tokens and stores the matching secret bundle
 * (encrypted) in Redis for the secret-proxy Go daemon to resolve. The byte
 * format here is the PHP half of the PHP<->Go contract verified by
 * secret-proxy/internal/vaultcrypto/crypto_test.go — do not change one side
 * without the other.
 *
 *   token = b64url(tokenId[16]) "." b64url(HMAC_SHA256(tokenId, key))
 *   blob  = base64( nonce[12] || ciphertext || tag[16] )   AAD = tokenId
 */
class RunSecretVault
{
    private const KEY_PREFIX = 'secret_proxy:run:';

    /**
     * @param  array{anthropic_oauth: ?string, mcp: array<string, array{url: string, auth: string}>, allowed_hosts: array<int, string>}  $bundle
     * @return string The opaque run token to hand to the agent.
     */
    public function issue(array $bundle, int $ttlSeconds): string
    {
        $key = $this->key();
        $tokenId = random_bytes(16);
        $nonce = random_bytes(12);

        $plaintext = json_encode($bundle, JSON_UNESCAPED_SLASHES);
        if ($plaintext === false) {
            throw new RuntimeException('secret-proxy: failed to encode run bundle');
        }

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $tokenId,
            16,
        );
        if ($ciphertext === false) {
            throw new RuntimeException('secret-proxy: vault encryption failed');
        }

        $idPart = $this->b64url($tokenId);
        $blob = base64_encode($nonce.$ciphertext.$tag);

        $this->connection()->setex(self::KEY_PREFIX.$idPart, max(1, $ttlSeconds), $blob);

        $mac = hash_hmac('sha256', $tokenId, $key, true);

        return $idPart.'.'.$this->b64url($mac);
    }

    /**
     * Delete the vault entry for a token. Called in the run's finally block so a
     * leaked opaque token is inert the moment the run ends.
     */
    public function revoke(string $opaqueToken): void
    {
        $idPart = $this->idPart($opaqueToken);
        if ($idPart !== null) {
            $this->connection()->del(self::KEY_PREFIX.$idPart);
        }
    }

    /**
     * Number of live vault entries (used by the status MCP tool).
     */
    public function activeCount(): int
    {
        // SCAN, not KEYS — KEYS is O(N) and blocks the shared Redis instance.
        $conn = $this->connection();
        $cursor = '0';
        $count = 0;

        do {
            [$cursor, $keys] = $conn->scan($cursor, ['match' => self::KEY_PREFIX.'*', 'count' => 200]);
            $count += is_array($keys) ? count($keys) : 0;
        } while ((string) $cursor !== '0');

        return $count;
    }

    /**
     * Verify + decrypt a token (PHP parity with the Go daemon; used by tests).
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $opaqueToken): ?array
    {
        $parts = explode('.', $opaqueToken, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $tokenId = $this->b64urlDecode($parts[0]);
        $mac = $this->b64urlDecode($parts[1]);
        if ($tokenId === false || strlen($tokenId) !== 16 || $mac === false) {
            return null;
        }

        $key = $this->key();
        if (! hash_equals(hash_hmac('sha256', $tokenId, $key, true), $mac)) {
            return null;
        }

        $blob = $this->connection()->get(self::KEY_PREFIX.$parts[0]);
        if (! is_string($blob)) {
            return null;
        }

        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $nonce = substr($raw, 0, 12);
        $tag = substr($raw, -16);
        $ciphertext = substr($raw, 12, -16);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $tokenId,
        );
        if ($plaintext === false) {
            return null;
        }

        $decoded = json_decode($plaintext, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function key(): string
    {
        $raw = base64_decode((string) config('secret_proxy.key'), true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new RuntimeException('secret-proxy: SECRET_PROXY_KEY must be base64 of 32 bytes');
        }

        // Hard separation from APP_KEY: reusing it would let an agent that can
        // reach the daemon share a key with every Crypt::/encrypt() value in the
        // app, and make the vault key recoverable from any other encrypted artifact.
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appRaw = base64_decode(substr($appKey, 7), true);
            if ($appRaw !== false && strlen($appRaw) === 32 && hash_equals($appRaw, $raw)) {
                throw new RuntimeException('secret-proxy: SECRET_PROXY_KEY must not reuse APP_KEY');
            }
        }

        return $raw;
    }

    private function connection(): Connection
    {
        return Redis::connection((string) config('secret_proxy.redis_connection', 'secret_proxy'));
    }

    private function idPart(string $opaqueToken): ?string
    {
        $parts = explode('.', $opaqueToken, 2);

        return ($parts[0] ?? '') !== '' ? $parts[0] : null;
    }

    private function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function b64urlDecode(string $value): string|false
    {
        $padded = $value.str_repeat('=', (4 - strlen($value) % 4) % 4);

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
