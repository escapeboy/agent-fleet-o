<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\DTOs\ChatMessageDTO;
use App\Domain\AgentChatProtocol\DTOs\StructuredRequestDTO;

/**
 * Wrap/unwrap ACP payloads in the Agentverse mailbox envelope format:
 *
 *   {
 *     version: int,
 *     sender: string (bech32 agent address),
 *     target: string (bech32 agent address),
 *     session: string (uuid),
 *     schema_digest: string (sha256 of protocol schema),
 *     payload: <our raw ACP payload>
 *   }
 *
 * Agentverse wraps every mailbox message this way. Our ProtocolReceiver speaks
 * raw ACP, so we mapping between the two for agentverse_mailbox adapter kinds.
 */
class AgentverseEnvelopeMapper
{
    public const ENVELOPE_VERSION = 1;

    /**
     * Wrap an outbound ChatMessageDTO or StructuredRequestDTO in an Agentverse envelope.
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
            'session' => $dto->sessionId,
            'schema_digest' => $schemaDigest ?? (string) config('agent_chat.protocol_manifest_uri'),
            'payload' => $dto->toArray(),
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

        return [
            'payload' => (array) $envelope['payload'],
            'sender' => (string) $envelope['sender'],
            'target' => (string) $envelope['target'],
            'session' => (string) $envelope['session'],
        ];
    }
}
