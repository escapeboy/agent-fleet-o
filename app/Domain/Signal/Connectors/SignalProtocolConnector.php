<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Polls a signal-cli-rest-api sidecar for new messages.
 *
 * Setup: run `bbernhard/signal-cli-rest-api` as a Docker sidecar.
 * The sidecar exposes a REST API on port 8080 by default.
 *
 * Config keys:
 *   api_url        — sidecar base URL (e.g. http://signal-sidecar:8080)
 *   phone_number   — registered Signal phone number (e.g. +15551234567)
 *   processed_ids  — array of already-processed message IDs (dedup cursor)
 *   team_id        — team that owns this connector
 */
class SignalProtocolConnector implements InputConnectorInterface
{
    private array $newProcessedIds = [];

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        $phoneNumber = $config['phone_number'] ?? null;
        $teamId = $config['team_id'] ?? null;
        $processedIds = $config['processed_ids'] ?? [];

        if (! $apiUrl || ! $phoneNumber) {
            Log::warning('SignalProtocolConnector: api_url and phone_number are required');

            return [];
        }

        try {
            $response = Http::timeout(30)->get("{$apiUrl}/v1/receive/{$phoneNumber}");

            if (! $response->successful()) {
                Log::warning('SignalProtocolConnector: receive failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 200),
                ]);

                return [];
            }

            $messages = $response->json() ?? [];
            $signals = [];

            foreach ($messages as $message) {
                $signal = $this->processMessage($message, $phoneNumber, $teamId, $processedIds);
                if ($signal) {
                    $signals[] = $signal;
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('SignalProtocolConnector: Error polling messages', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'signal_protocol';
    }

    public function getUpdatedConfig(array $config, array $signals): array
    {
        if (! empty($this->newProcessedIds)) {
            // Keep only last 500 IDs to prevent unbounded growth
            $all = array_merge($config['processed_ids'] ?? [], $this->newProcessedIds);
            $config['processed_ids'] = array_values(array_unique(array_slice($all, -500)));
        }

        return $config;
    }

    private function processMessage(array $message, string $phoneNumber, ?string $teamId, array $processedIds): ?Signal
    {
        $envelope = $message['envelope'] ?? $message;
        $dataMessage = $envelope['dataMessage'] ?? null;

        if (! $dataMessage) {
            return null;
        }

        // Skip own messages (sent by the bot number itself)
        $sourceNumber = $envelope['sourceNumber'] ?? $envelope['source'] ?? null;
        if ($sourceNumber === $phoneNumber) {
            return null;
        }

        $timestamp = $envelope['timestamp'] ?? $dataMessage['timestamp'] ?? null;
        $messageId = $sourceNumber.'_'.$timestamp;

        // Dedup by (source, timestamp) tuple
        if (in_array($messageId, $processedIds, true)) {
            return null;
        }

        $this->newProcessedIds[] = $messageId;

        $text = $dataMessage['message'] ?? '';
        $sourceName = $envelope['sourceName'] ?? null;
        $groupId = $dataMessage['groupInfo']['groupId'] ?? null;

        $payload = array_filter([
            'text' => $text,
            'source_number' => $sourceNumber,
            'source_name' => $sourceName,
            'timestamp' => $timestamp,
            'group_id' => $groupId,
            'has_attachments' => ! empty($dataMessage['attachments']),
        ]);

        $hints = array_filter([
            'name' => $sourceName,
            'phone' => $sourceNumber,
        ]);

        return $this->ingestAction->execute(
            sourceType: 'signal_protocol',
            sourceIdentifier: $sourceNumber ?? 'unknown',
            payload: $payload,
            tags: array_filter(['signal_protocol', $groupId ? 'group' : 'direct']),
            sourceNativeId: "signal:{$messageId}",
            teamId: $teamId,
            senderHints: $hints,
        );
    }
}
