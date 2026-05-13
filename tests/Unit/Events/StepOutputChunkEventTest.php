<?php

namespace Tests\Unit\Events;

use App\Events\StepOutputChunk;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class StepOutputChunkEventTest extends TestCase
{
    public function test_broadcasts_on_correct_private_channel(): void
    {
        $event = new StepOutputChunk(
            experimentId: 'exp-123',
            stepId: 'step-456',
            chunk: 'output text',
        );

        $channels = $event->broadcastOn();

        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-experiment.exp-123', $channels[0]->name);
    }

    public function test_broadcasts_with_correct_event_name(): void
    {
        $event = new StepOutputChunk(
            experimentId: 'exp-123',
            stepId: 'step-456',
            chunk: 'output text',
        );

        $this->assertSame('StepOutputChunk', $event->broadcastAs());
    }

    public function test_broadcasts_with_correct_payload(): void
    {
        $event = new StepOutputChunk(
            experimentId: 'exp-123',
            stepId: 'step-456',
            chunk: 'Hello from agent',
        );

        $payload = $event->broadcastWith();

        $this->assertSame('step-456', $payload['stepId']);
        $this->assertSame('Hello from agent', $payload['chunk']);
        $this->assertArrayNotHasKey('experimentId', $payload);
    }
}
