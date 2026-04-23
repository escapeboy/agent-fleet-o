<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

/**
 * Minimal HS256 JWT verifier for public agent endpoints.
 * Avoids adding a new composer dependency (firebase/php-jwt, lcobucci/jwt).
 */
class HmacJwtVerifier
{
    /**
     * @return array<string, mixed> decoded claims
     *
     * @throws \InvalidArgumentException on any validation failure
     */
    public function verify(string $token, string $secret): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('JWT must have 3 parts');
        }

        [$headerB64, $payloadB64, $sigB64] = $parts;

        $header = json_decode($this->b64UrlDecode($headerB64), true);
        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new \InvalidArgumentException('Only HS256 is supported');
        }

        $expected = $this->b64UrlEncode(hash_hmac('sha256', $headerB64.'.'.$payloadB64, $secret, true));
        if (! hash_equals($expected, $sigB64)) {
            throw new \InvalidArgumentException('Signature mismatch');
        }

        $claims = json_decode($this->b64UrlDecode($payloadB64), true);
        if (! is_array($claims)) {
            throw new \InvalidArgumentException('Invalid payload');
        }

        $now = time();
        if (isset($claims['exp']) && (int) $claims['exp'] < $now) {
            throw new \InvalidArgumentException('Token expired');
        }
        if (isset($claims['nbf']) && (int) $claims['nbf'] > $now) {
            throw new \InvalidArgumentException('Token not yet valid');
        }

        return $claims;
    }

    /**
     * Helper to mint tokens (for tests / UI to share with integrators).
     *
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims, string $secret, int $ttlSeconds = 3600): string
    {
        $now = time();
        $claims['iat'] = $claims['iat'] ?? $now;
        $claims['exp'] = $claims['exp'] ?? $now + $ttlSeconds;

        $header = $this->b64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $payload = $this->b64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $signature = $this->b64UrlEncode(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function b64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function b64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
