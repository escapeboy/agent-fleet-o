<?php

namespace Tests\Unit\Events;

use App\Events\AssistantMessageChunk;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class AssistantMessageChunkEventTest extends TestCase
{
    public function test_broadcasts_on_correct_private_channel(): void
    {
        $event = new AssistantMessageChunk(
            conversationId: 'conv-123',
            placeholderId: 'msg-456',
            content: 'Hello',
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-assistant.conv-123', $channels[0]->name);
    }

    public function test_broadcasts_with_correct_event_name(): void
    {
        $event = new AssistantMessageChunk(
            conversationId: 'conv-123',
            placeholderId: 'msg-456',
            content: 'Hello',
        );

        $this->assertSame('AssistantMessageChunk', $event->broadcastAs());
    }

    public function test_broadcasts_with_correct_payload(): void
    {
        $event = new AssistantMessageChunk(
            conversationId: 'conv-123',
            placeholderId: 'msg-456',
            content: 'Hello world',
            toolCallsInProgress: ['search_agents'],
        );

        $payload = $event->broadcastWith();

        $this->assertSame('msg-456', $payload['placeholderId']);
        $this->assertSame('Hello world', $payload['content']);
        $this->assertSame(['search_agents'], $payload['toolCallsInProgress']);
    }

    public function test_tool_calls_default_to_empty_array(): void
    {
        $event = new AssistantMessageChunk(
            conversationId: 'c',
            placeholderId: 'm',
            content: '',
        );

        $this->assertSame([], $event->broadcastWith()['toolCallsInProgress']);
    }
}
