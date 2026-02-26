<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Polls a Matrix homeserver for new messages via the Client-Server API /_matrix/client/v3/sync.
 *
 * Uses `next_batch` token as a cursor to fetch only events since last poll.
 * Filters own bot messages to prevent reply loops.
 *
 * Config keys:
 *   homeserver_url — Matrix homeserver URL (e.g. https://matrix.org)
 *   access_token   — Matrix bot access token
 *   bot_user_id    — Bot's Matrix user ID (e.g. @fleetbot:matrix.org) — used to filter own messages
 *   next_batch     — Sync cursor (updated after each poll)
 *   room_ids       — Optional array of room IDs to filter (empty = all rooms)
 *   team_id        — Team that owns this connector
 */
class MatrixConnector implements InputConnectorInterface
{
    private ?string $newNextBatch = null;

    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $homeserverUrl = rtrim($config['homeserver_url'] ?? '', '/');
        $accessToken = $config['access_token'] ?? null;
        $botUserId = $config['bot_user_id'] ?? null;
        $nextBatch = $config['next_batch'] ?? null;
        $allowedRooms = $config['room_ids'] ?? [];
        $teamId = $config['team_id'] ?? null;

        if (! $homeserverUrl || ! $accessToken) {
            Log::warning('MatrixConnector: homeserver_url and access_token are required');

            return [];
        }

        try {
            $params = [
                'access_token' => $accessToken,
                'timeout' => 0,    // Non-blocking for cron-based polling
                'filter' => json_encode([
                    'room' => [
                        'timeline' => ['limit' => 50, 'types' => ['m.room.message']],
                        'state' => ['limit' => 0],
                        'ephemeral' => ['limit' => 0],
                    ],
                    'presence' => ['limit' => 0],
                ]),
            ];

            if ($nextBatch) {
                $params['since'] = $nextBatch;
            }

            $response = Http::timeout(35)
                ->withToken($accessToken)
                ->get("{$homeserverUrl}/_matrix/client/v3/sync", $params);

            if (! $response->successful()) {
                Log::warning('MatrixConnector: sync failed', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return [];
            }

            $data = $response->json();
            $this->newNextBatch = $data['next_batch'] ?? null;

            $rooms = $data['rooms']['join'] ?? [];
            $signals = [];

            foreach ($rooms as $roomId => $roomData) {
                // Filter by allowed rooms if specified
                if (! empty($allowedRooms) && ! in_array($roomId, $allowedRooms, true)) {
                    continue;
                }

                $events = $roomData['timeline']['events'] ?? [];

                foreach ($events as $event) {
                    $signal = $this->processEvent($event, $roomId, $botUserId, $teamId);
                    if ($signal) {
                        $signals[] = $signal;
                    }
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            Log::error('MatrixConnector: Error during sync', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'matrix';
    }

    public function getUpdatedConfig(array $config, array $signals): array
    {
        if ($this->newNextBatch !== null) {
            $config['next_batch'] = $this->newNextBatch;
        }

        return $config;
    }

    private function processEvent(array $event, string $roomId, ?string $botUserId, ?string $teamId): ?Signal
    {
        if (($event['type'] ?? '') !== 'm.room.message') {
            return null;
        }

        $sender = $event['sender'] ?? null;
        $eventId = $event['event_id'] ?? null;

        // Skip own bot messages to prevent reply loops
        if ($botUserId && $sender === $botUserId) {
            return null;
        }

        $content = $event['content'] ?? [];
        $msgtype = $content['msgtype'] ?? '';

        // Only process text messages (m.text) and notice messages
        if (! in_array($msgtype, ['m.text', 'm.notice', 'm.emote'], true)) {
            return null;
        }

        $body = $content['body'] ?? '';
        $formattedBody = $content['formatted_body'] ?? null;

        $payload = array_filter([
            'text' => $body,
            'formatted_body' => $formattedBody,
            'sender' => $sender,
            'room_id' => $roomId,
            'event_id' => $eventId,
            'msgtype' => $msgtype,
            'origin_server_ts' => $event['origin_server_ts'] ?? null,
        ]);

        $hints = array_filter([
            'name' => $sender,
        ]);

        return $this->ingestAction->execute(
            sourceType: 'matrix',
            sourceIdentifier: $sender ?? 'unknown',
            payload: $payload,
            tags: ['matrix', $roomId],
            sourceNativeId: $eventId ? "matrix:{$eventId}" : null,
            teamId: $teamId,
            senderHints: $hints,
        );
    }
}
