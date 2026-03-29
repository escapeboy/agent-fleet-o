<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WorkflowNodeUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $experimentId,
        public readonly string $nodeId,
        public readonly string $status,
        public readonly int $durationMs = 0,
        public readonly int $tokenCount = 0,
        public readonly float $cost = 0.0,
        public readonly string $outputPreview = '',
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("experiment.{$this->experimentId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'WorkflowNodeUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'experimentId' => $this->experimentId,
            'nodeId' => $this->nodeId,
            'status' => $this->status,
            'durationMs' => $this->durationMs,
            'tokenCount' => $this->tokenCount,
            'cost' => $this->cost,
            'outputPreview' => $this->outputPreview,
        ];
    }
}
