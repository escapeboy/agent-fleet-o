<?php

namespace App\Infrastructure\Bridge\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcasts an agent/LLM request to the bridge daemon via Reverb.
 *
 * The bridge daemon (using the `start` command) subscribes to the
 * private-daemon.{teamId} channel and listens for `agent.request` events.
 *
 * Uses ShouldBroadcastNow to avoid queuing — the calling code already
 * runs inside a queue job and needs the request delivered immediately.
 */
class BridgeAgentRequest implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly string $teamId,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("daemon.{$this->teamId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'agent.request';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
