<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssistantMessageChunk implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $conversationId,
        public readonly string $placeholderId,
        public readonly string $content,
        public readonly array $toolCallsInProgress = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('assistant.'.$this->conversationId)];
    }

    public function broadcastAs(): string
    {
        return 'AssistantMessageChunk';
    }

    public function broadcastWith(): array
    {
        return [
            'placeholderId' => $this->placeholderId,
            'content' => $this->content,
            'toolCallsInProgress' => $this->toolCallsInProgress,
        ];
    }
}
