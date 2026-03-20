<?php

namespace Tests\Unit\Domain\Memory\Services;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
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
        config(['memory.unified_search.enabled' => false]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection);

        $injector = new MemoryContextInjector($retrieve);

        $result = $injector->buildContext('agent-123', 'some input');

        $this->assertNull($result);
    }

    public function test_builds_context_from_memories_with_attribution(): void
    {
        config(['memory.enabled' => true]);
        config(['memory.unified_search.enabled' => false]);

        $memory1 = (object) [
            'content' => 'Memory content 1',
            'source_type' => 'execution',
            'effective_importance' => 0.7,
            'created_at' => now()->subDay(),
            'retrieval_count' => 3,
        ];
        $memory2 = (object) [
            'content' => 'Memory content 2',
            'source_type' => 'manual_upload',
            'effective_importance' => 0.5,
            'created_at' => now()->subWeek(),
            'retrieval_count' => 0,
        ];

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection([$memory1, $memory2]));

        $injector = new MemoryContextInjector($retrieve);

        $result = $injector->buildContext('agent-123', 'test input');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Relevant Context', $result);
        $this->assertStringContainsString('Memory content 1', $result);
        $this->assertStringContainsString('Memory content 2', $result);
        $this->assertStringContainsString('source: execution', $result);
        $this->assertStringContainsString('importance: 7/10', $result);
        $this->assertStringContainsString('used: 3x', $result);
    }

    public function test_converts_array_input_to_json(): void
    {
        config(['memory.enabled' => true]);
        config(['memory.unified_search.enabled' => false]);

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

    public function test_unified_search_builds_context_with_rrf_results(): void
    {
        config(['memory.enabled' => true]);
        config(['memory.unified_search.enabled' => true]);

        $unifiedResults = collect([
            [
                'type' => 'memory',
                'content' => 'Vector memory result',
                'score' => 0.032,
                'metadata' => [
                    'source_type' => 'execution',
                    'importance' => 0.8,
                    'retrieval_count' => 5,
                    'created_at' => now()->subDays(2)->toIso8601String(),
                ],
            ],
            [
                'type' => 'kg_fact',
                'content' => 'Entity A uses Entity B',
                'score' => 0.028,
                'metadata' => [
                    'source_entity' => 'Entity A',
                    'target_entity' => 'Entity B',
                    'relation_type' => 'uses',
                    'valid_at' => now()->subMonth()->toIso8601String(),
                ],
            ],
        ]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $unifiedSearch = Mockery::mock(UnifiedMemorySearchAction::class);
        $unifiedSearch->shouldReceive('execute')
            ->once()
            ->andReturn($unifiedResults);

        $injector = new MemoryContextInjector($retrieve, $unifiedSearch);

        $result = $injector->buildContext('agent-123', 'test query', null, 'team-123');

        $this->assertNotNull($result);
        $this->assertStringContainsString('Relevant Context', $result);
        $this->assertStringContainsString('Vector memory result', $result);
        $this->assertStringContainsString('Entity A uses Entity B', $result);
        $this->assertStringContainsString('source: kg_fact', $result);
        $this->assertStringContainsString('Entity A', $result);
        $this->assertStringContainsString('Entity B', $result);
        $this->assertStringContainsString('relation: uses', $result);
    }

    public function test_unified_search_falls_back_to_vector_without_team_id(): void
    {
        config(['memory.enabled' => true]);
        config(['memory.unified_search.enabled' => true]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection);

        $unifiedSearch = Mockery::mock(UnifiedMemorySearchAction::class);
        $unifiedSearch->shouldNotHaveBeenCalled();

        $injector = new MemoryContextInjector($retrieve, $unifiedSearch);

        // No teamId → falls back to vector-only
        $result = $injector->buildContext('agent-123', 'test query');

        $this->assertNull($result);
    }

    public function test_consolidated_memory_shows_observation_count(): void
    {
        config(['memory.enabled' => true]);
        config(['memory.unified_search.enabled' => true]);

        $unifiedResults = collect([
            [
                'type' => 'memory',
                'content' => 'Consolidated insight',
                'score' => 0.05,
                'metadata' => [
                    'source_type' => 'consolidated',
                    'importance' => 0.9,
                    'retrieval_count' => 10,
                    'created_at' => now()->toIso8601String(),
                    'metadata' => ['source_ids' => ['id1', 'id2', 'id3', 'id4']],
                ],
            ],
        ]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $unifiedSearch = Mockery::mock(UnifiedMemorySearchAction::class);
        $unifiedSearch->shouldReceive('execute')
            ->once()
            ->andReturn($unifiedResults);

        $injector = new MemoryContextInjector($retrieve, $unifiedSearch);

        $result = $injector->buildContext('agent-123', 'test', null, 'team-123');

        $this->assertStringContainsString('based_on: 4 observations', $result);
        $this->assertStringContainsString('source: consolidated', $result);
        $this->assertStringContainsString('used: 10x', $result);
    }
}
