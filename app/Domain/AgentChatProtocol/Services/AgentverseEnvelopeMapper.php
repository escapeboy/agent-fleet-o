<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\DTOs\StructuredRequestDTO;
use Illuminate\Support\Str;

/**
 * Wrap/unwrap ACP payloads in the Agentverse mailbox envelope format per
 * docs.agentverse.ai API reference (verified 2026-04-24 browser probe):
 *
 *   {
 *     version: int       (required — integer, 1 in current impl),
 *     sender: string     (required — caller identifier, e.g. fleetq:team:<uuid>),
 *     target: string     (required — recipient bech32 agent1q... address),
 *     session: string    (required — UUID v4),
 *     schema_digest: string (required — sha256 of the ACP manifest),
 *     protocol_digest: string (optional),
 *     payload: string    (optional — JSON-encoded ACP payload as string),
 *     expires: int       (optional),
 *     nonce: int         (optional),
 *     signature: string  (optional — cryptographic signature),
 *   }
 *
 * Gotchas caught in probe:
 *   - session MUST be UUIDv4 (not UUIDv7); v7 UUIDs are rejected with 422.
 *   - payload MUST be a string (JSON-encoded ACP payload); dict is rejected.
 */
class AgentverseEnvelopeMapper
{
    public const ENVELOPE_VERSION = 1;

    /**
     * Wrap an outbound ChatMessageDTO or StructuredRequestDTO in an Agentverse envelope.
     *
     * @return array<string, mixed>
     */
    public function wrap(
        ChatMessageDTO|StructuredRequestDTO $dto,
        string $callerAddress,
        string $targetAddress,
        ?string $schemaDigest = null,
    ): array {
        return [
            'version' => self::ENVELOPE_VERSION,
            'sender' => $callerAddress,
            'target' => $targetAddress,
            'session' => $this->toUuidV4($dto->sessionId),
            'schema_digest' => $schemaDigest ?? (string) config('agent_chat.protocol_manifest_uri'),
            'payload' => json_encode($dto->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Extract the raw ACP payload from an incoming Agentverse envelope.
     *
     * @return array{payload: array<string, mixed>, sender: string, target: string, session: string}
     */
    public function unwrap(array $envelope): array
    {
        if (! isset($envelope['payload'], $envelope['sender'], $envelope['target'], $envelope['session'])) {
            throw new \InvalidArgumentException('Malformed Agentverse envelope: missing required fields');
        }

        $rawPayload = $envelope['payload'];
        $decoded = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Malformed Agentverse envelope: payload is not a JSON object');
        }

        return [
            'payload' => $decoded,
            'sender' => (string) $envelope['sender'],
            'target' => (string) $envelope['target'],
            'session' => (string) $envelope['session'],
        ];
    }

    /**
     * Derive a stable UUIDv4 from an arbitrary session token.
     * Agentverse rejects UUIDv7 with HTTP 422, so we rewrite our internal
     * session ids to v4 for the envelope — the mapping is deterministic per
     * input so repeated calls with the same session token produce the same v4.
     */
    private function toUuidV4(string $token): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $token) === 1) {
            return strtolower($token);
        }

        $hash = hash('sha256', $token, true);
        $bytes = substr($hash, 0, 16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    public function generateSessionId(): string
    {
        return (string) Str::uuid();
    }
}
