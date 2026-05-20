<?php

namespace Tests\Unit\Domain\Experiment\Services;

use App\Domain\Experiment\Services\OutputTruncator;
use App\Domain\Experiment\Services\StepOutputBroadcaster;
use App\Events\StepOutputChunk;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class StepOutputBroadcasterStreamingTest extends TestCase
{
    private StepOutputBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broadcaster = new StepOutputBroadcaster(new OutputTruncator);
    }

    public function test_broadcast_chunk_fires_step_output_chunk_event(): void
    {
        Event::fake([StepOutputChunk::class]);

        $this->broadcaster->broadcastChunk('step-1', 'hello', 'exp-1');

        Event::assertDispatched(StepOutputChunk::class, function (StepOutputChunk $event) {
            return $event->experimentId === 'exp-1'
                && $event->stepId === 'step-1'
                && $event->chunk === 'hello';
        });
    }

    public function test_broadcast_chunk_does_not_fire_event_when_experiment_id_is_null(): void
    {
        Event::fake([StepOutputChunk::class]);

        $this->broadcaster->broadcastChunk('step-2', 'hello');

        Event::assertNotDispatched(StepOutputChunk::class);
    }

    public function test_broadcast_chunk_appends_to_redis(): void
    {
        $stepId = 'step-redis-'.uniqid();
        Redis::del("step_stream:{$stepId}");

        $this->broadcaster->broadcastChunk($stepId, 'chunk-a');
        $this->broadcaster->broadcastChunk($stepId, 'chunk-b');

        $this->assertSame('chunk-achunk-b', Redis::get("step_stream:{$stepId}"));

        Redis::del("step_stream:{$stepId}");
    }

    public function test_broadcast_chunk_still_appends_to_redis_when_reverb_throws(): void
    {
        $stepId = 'step-throw-'.uniqid();
        Redis::del("step_stream:{$stepId}");

        Event::shouldReceive('dispatch')->andThrow(new \RuntimeException('Reverb down'));

        // Should not throw
        $this->broadcaster->broadcastChunk($stepId, 'resilient', 'exp-x');

        $this->assertSame('resilient', Redis::get("step_stream:{$stepId}"));

        Redis::del("step_stream:{$stepId}");
    }

    public function test_broadcast_chunk_does_not_throw_when_reverb_unavailable(): void
    {
        Event::fake([StepOutputChunk::class]);
        Event::shouldReceive('dispatch')->andThrow(new \RuntimeException('connection refused'));

        // No exception should propagate
        $this->broadcaster->broadcastChunk('step-safe', 'x', 'exp-safe');

        $this->assertTrue(true); // if we got here, no exception was thrown
    }
}
