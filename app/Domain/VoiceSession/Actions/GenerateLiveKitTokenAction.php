<?php

namespace App\Domain\VoiceSession\Actions;

use App\Domain\VoiceSession\Exceptions\VoiceSessionException;

/**
 * Generates a LiveKit JWT token for a participant to join a room.
 *
 * LiveKit uses standard HS256 JWTs with a `video` claim block.
 * No external SDK required — we generate the token manually using
 * PHP's built-in base64 and hash_hmac functions to avoid a composer dependency.
 *
 * Token format: https://docs.livekit.io/home/get-started/authentication/
 */
class GenerateLiveKitTokenAction
{
    /**
     * Generate a participant token for a LiveKit room.
     *
     * @param  string  $roomName  LiveKit room name (stored on VoiceSession)
     * @param  string  $participantIdentity  Unique identity for this participant (e.g. "user-{id}")
     * @param  bool  $canPublish  Whether the participant can publish audio/video tracks
     * @param  bool  $canSubscribe  Whether the participant can subscribe to other tracks
     * @return string Signed JWT token
     *
     * @throws VoiceSessionException
     */
    public function execute(
        string $roomName,
        string $participantIdentity,
        bool $canPublish = true,
        bool $canSubscribe = true,
    ): string {
        $apiKey = config('livekit.api_key');
        $apiSecret = config('livekit.api_secret');

        if (empty($apiKey) || empty($apiSecret)) {
            throw VoiceSessionException::missingConfiguration();
        }

        $ttl = (int) config('livekit.token_ttl', 3600);
        $now = time();

        $header = $this->base64UrlEncode(json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT',
        ]));

        $payload = $this->base64UrlEncode(json_encode([
            'iss' => $apiKey,
            'sub' => $participantIdentity,
            'iat' => $now,
            'exp' => $now + $ttl,
            'nbf' => $now,
            'jti' => bin2hex(random_bytes(16)),
            'video' => [
                'roomJoin' => true,
                'room' => $roomName,
                'canPublish' => $canPublish,
                'canSubscribe' => $canSubscribe,
            ],
        ]));

        $signingInput = "{$header}.{$payload}";
        $signature = $this->base64UrlEncode(
            hash_hmac('sha256', $signingInput, $apiSecret, true),
        );

        return "{$signingInput}.{$signature}";
    }

    /** Base64URL-encode a string (RFC 4648 §5 — no padding, URL-safe chars). */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
