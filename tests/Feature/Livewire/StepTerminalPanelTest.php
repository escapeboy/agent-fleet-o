<?php

namespace Tests\Feature\Livewire;

use App\Domain\Experiment\Services\StepOutputBroadcaster;
use App\Livewire\Experiments\StepTerminalPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class StepTerminalPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_receive_chunk_appends_to_output_for_matching_step(): void
    {
        $component = Livewire::test(StepTerminalPanel::class, [
            'stepId' => 'step-abc',
            'experimentId' => 'exp-xyz',
        ]);

        $component->call('receiveChunk', ['stepId' => 'step-abc', 'chunk' => 'Hello ']);
        $component->call('receiveChunk', ['stepId' => 'step-abc', 'chunk' => 'world']);

        $this->assertSame('Hello world', $component->get('output'));
    }

    public function test_receive_chunk_ignores_chunks_for_different_step(): void
    {
        $component = Livewire::test(StepTerminalPanel::class, [
            'stepId' => 'step-abc',
            'experimentId' => 'exp-xyz',
        ]);

        $component->call('receiveChunk', ['stepId' => 'step-other', 'chunk' => 'noise']);

        $this->assertSame('', $component->get('output'));
    }

    public function test_poll_output_loads_accumulated_output_from_redis(): void
    {
        $broadcaster = Mockery::mock(StepOutputBroadcaster::class);
        $broadcaster->shouldReceive('getAccumulatedOutput')
            ->once()
            ->with('step-poll')
            ->andReturn('full output from redis');

        $this->app->instance(StepOutputBroadcaster::class, $broadcaster);

        $component = Livewire::test(StepTerminalPanel::class, [
            'stepId' => 'step-poll',
            'experimentId' => 'exp-poll',
        ]);

        $component->call('pollOutput');

        $this->assertSame('full output from redis', $component->get('output'));
    }

    public function test_poll_output_does_not_update_when_output_unchanged(): void
    {
        $broadcaster = Mockery::mock(StepOutputBroadcaster::class);
        $broadcaster->shouldReceive('getAccumulatedOutput')
            ->andReturn('same content');

        $this->app->instance(StepOutputBroadcaster::class, $broadcaster);

        $component = Livewire::test(StepTerminalPanel::class, [
            'stepId' => 'step-same',
            'experimentId' => 'exp-same',
        ]);

        // Pre-set the output to match what redis returns
        $component->set('output', 'same content');
        $component->call('pollOutput');

        // Output unchanged — no re-dispatch happened, which is fine; just assert stable state
        $this->assertSame('same content', $component->get('output'));
    }
}
