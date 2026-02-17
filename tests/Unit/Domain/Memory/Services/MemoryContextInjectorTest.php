<?php

namespace Tests\Unit\Domain\Memory\Services;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Services\MemoryContextInjector;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class MemoryContextInjectorTest extends TestCase
{
    public function test_returns_null_when_memory_disabled(): void
    {
        config(['memory.enabled' => false]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $injector = new MemoryContextInjector($retrieve);

        $result = $injector->buildContext('agent-123', 'test input');

        $this->assertNull($result);
        $retrieve->shouldNotHaveBeenCalled();
    }

    public function test_returns_null_when_input_is_empty(): void
    {
        config(['memory.enabled' => true]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $injector = new MemoryContextInjector($retrieve);

        $result = $injector->buildContext('agent-123', '');

        $this->assertNull($result);
    }

    public function test_returns_null_when_no_memories_found(): void
    {
        config(['memory.enabled' => true]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection);

        $injector = new MemoryContextInjector($retrieve);

        $result = $injector->buildContext('agent-123', 'some input');

        $this->assertNull($result);
    }

    public function test_builds_context_from_memories(): void
    {
        config(['memory.enabled' => true]);

        $memory1 = (object) ['content' => 'Memory content 1'];
        $memory2 = (object) ['content' => 'Memory content 2'];

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection([$memory1, $memory2]));

        $injector = new MemoryContextInjector($retrieve);

        $result = $injector->buildContext('agent-123', 'test input');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Relevant Context from Past Executions', $result);
        $this->assertStringContainsString('Memory content 1', $result);
        $this->assertStringContainsString('Memory content 2', $result);
    }

    public function test_converts_array_input_to_json(): void
    {
        config(['memory.enabled' => true]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->withArgs(function ($agentId, $query) {
                return str_contains($query, 'task_key');
            })
            ->once()
            ->andReturn(new Collection);

        $injector = new MemoryContextInjector($retrieve);

        $injector->buildContext('agent-123', ['task_key' => 'value']);

        $retrieve->shouldHaveReceived('execute');
    }
}
