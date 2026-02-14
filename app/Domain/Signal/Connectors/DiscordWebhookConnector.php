<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Log;

class DiscordWebhookConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * Ingest a Discord interaction/event as a signal.
     *
     * Config expects: ['payload' => array, 'experiment_id' => ?string]
     *
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $payload = $config['payload'] ?? [];
        $experimentId = $config['experiment_id'] ?? null;

        // Skip PING interactions (type 1) — those are handled in the controller
        $type = $payload['type'] ?? 0;
        if ($type === 1) {
            return [];
        }

        $messageData = $payload['data'] ?? $payload;
        $content = $messageData['content'] ?? '';
        $authorId = $payload['member']['user']['id'] ?? $payload['user']['id'] ?? 'unknown';
        $authorName = $payload['member']['user']['username'] ?? $payload['user']['username'] ?? 'unknown';
        $channelId = $payload['channel_id'] ?? null;
        $guildId = $payload['guild_id'] ?? null;

        if (empty($content)) {
            Log::debug('DiscordWebhookConnector: Empty content in payload');

            return [];
        }

        $signalPayload = [
            'content' => $content,
            'author_id' => $authorId,
            'author_name' => $authorName,
            'channel_id' => $channelId,
            'guild_id' => $guildId,
            'interaction_type' => $type,
            'message_id' => $payload['id'] ?? null,
        ];

        $signal = $this->ingestAction->execute(
            sourceType: 'discord',
            sourceIdentifier: $authorId,
            payload: $signalPayload,
            tags: ['discord'],
            experimentId: $experimentId,
        );

        return $signal ? [$signal] : [];
    }

    public function supports(string $driver): bool
    {
        return $driver === 'discord';
    }

    /**
     * Validate Discord webhook signature (Ed25519).
     */
    public static function validateSignature(string $timestamp, string $body, string $signature, string $publicKey): bool
    {
        if (! extension_loaded('sodium')) {
            Log::error('DiscordWebhookConnector: sodium extension required for Ed25519 verification');

            return false;
        }

        try {
            $message = $timestamp.$body;
            $signatureBytes = sodium_hex2bin($signature);
            $publicKeyBytes = sodium_hex2bin($publicKey);

            return sodium_crypto_sign_verify_detached($signatureBytes, $message, $publicKeyBytes);
        } catch (\Throwable $e) {
            Log::warning('DiscordWebhookConnector: Signature validation failed', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
