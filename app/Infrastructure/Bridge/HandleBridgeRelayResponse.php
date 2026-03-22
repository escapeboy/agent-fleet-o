<?php

namespace App\Infrastructure\Bridge;

use Laravel\Reverb\Events\MessageReceived;

/**
 * Listens to Reverb MessageReceived events and pushes bridge relay chunks
 * into the Redis stream consumed by LocalBridgeGateway::routeRequest().
 *
 * This listener runs synchronously within Reverb's process. The operations
 * are pure Redis writes (sub-millisecond) so they won't block the event loop.
 *
 * NOTE: Cannot use ShouldQueue because MessageReceived contains a Connection
 * object that is not serializable for queue transport.
 */
class HandleBridgeRelayResponse
{
    public function __construct(private readonly BridgeRequestRegistry $registry) {}

    public function handle(MessageReceived $event): void
    {
        $decoded = json_decode($event->message, true);

        if (! is_array($decoded)) {
            return;
        }

        $pusherEvent = $decoded['event'] ?? '';

        if ($pusherEvent === 'client-relay.chunk') {
            $this->handleChunk($decoded);
        } elseif ($pusherEvent === 'client-relay.error') {
            $this->handleError($decoded);
        }
    }

    private function handleChunk(array $decoded): void
    {
        // Pusher protocol: data is a JSON-encoded string within the outer message
        $data = is_string($decoded['data'] ?? null)
            ? json_decode($decoded['data'], true)
            : ($decoded['data'] ?? []);

        $requestId = $data['request_id'] ?? null;

        if (! $requestId) {
            return;
        }

        $done = (bool) ($data['done'] ?? false);
        $usage = $done ? ($data['usage'] ?? null) : null;

        $this->registry->pushChunk(
            requestId: $requestId,
            chunk: $data['chunk'] ?? '',
            done: $done,
            usage: $usage,
        );

        if ($done && $usage) {
            $this->registry->storeUsage($requestId, $usage);
        }
    }

    private function handleError(array $decoded): void
    {
        $data = is_string($decoded['data'] ?? null)
            ? json_decode($decoded['data'], true)
            : ($decoded['data'] ?? []);

        $requestId = $data['request_id'] ?? null;

        if (! $requestId) {
            return;
        }

        // Push a synthetic error sentinel so the BLPOP consumer wakes up
        $this->registry->pushChunk(
            requestId: $requestId,
            chunk: '',
            done: true,
            usage: null,
        );

        // Store the error message so LocalBridgeGateway can re-throw it
        $this->registry->storeUsage($requestId, [
            '__error' => $data['error'] ?? $data['message'] ?? 'Unknown bridge execution error',
        ]);
    }
}
