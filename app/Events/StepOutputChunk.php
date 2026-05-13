<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StepOutputChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $experimentId,
        public readonly string $stepId,
        public readonly string $chunk,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('experiment.'.$this->experimentId)];
    }

    public function broadcastAs(): string
    {
        return 'StepOutputChunk';
    }

    public function broadcastWith(): array
    {
        return [
            'stepId' => $this->stepId,
            'chunk' => $this->chunk,
        ];
    }
}
