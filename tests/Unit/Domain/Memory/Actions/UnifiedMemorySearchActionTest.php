<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use Illuminate\Support\Collection;
use Mockery;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Tests\TestCase;

class UnifiedMemorySearchActionTest extends TestCase
{
    private function fakeEmbeddings(): void
    {
        $vector = array_fill(0, 1536, 0.1);

        Prism::fake([
            new EmbeddingResponse(
                embeddings: [new Embedding($vector)],
                usage: new EmbeddingsUsage(tokens: 10),
                meta: new Meta(id: 'test', model: 'text-embedding-3-small'),
            ),
        ]);
    }

    public function test_falls_back_to_vector_only_when_unified_disabled(): void
    {
        config(['memory.unified_search.enabled' => false]);

        $vectorSearch = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $kgService = Mockery::mock(TemporalKnowledgeGraphService::class);

        $vectorSearch->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection);

        $action = new UnifiedMemorySearchAction($vectorSearch, $kgService);

        $results = $action->execute(
            teamId: 'team-1',
            query: 'test query',
            agentId: 'agent-1',
        );

        $this->assertCount(0, $results);
        $kgService->shouldNotHaveBeenCalled();
    }

    public function test_returns_empty_collection_when_no_agent_and_unified_disabled(): void
    {
        config(['memory.unified_search.enabled' => false]);

        $vectorSearch = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $kgService = Mockery::mock(TemporalKnowledgeGraphService::class);

        $action = new UnifiedMemorySearchAction($vectorSearch, $kgService);

        $results = $action->execute(
            teamId: 'team-1',
            query: 'test query',
        );

        $this->assertTrue($results->isEmpty());
    }

    public function test_rrf_fusion_combines_results_from_multiple_sources(): void
    {
        config(['memory.unified_search.enabled' => true]);
        config(['memory.unified_search.vector_weight' => 1.0]);
        config(['memory.unified_search.kg_weight' => 2.0]);
        config(['memory.unified_search.keyword_weight' => 0.5]);
        config(['memory.unified_search.rrf_k' => 60]);

        $this->fakeEmbeddings();

        $vectorMemory = (object) [
            'id' => 'mem-1',
            'content' => 'Vector memory',
            'source_type' => 'execution',
            'agent_id' => 'agent-1',
            'effective_importance' => 0.7,
            'retrieval_count' => 3,
            'created_at' => now(),
            'confidence' => 0.9,
            'metadata' => [],
        ];

        $kgEdge = (object) [
            'id' => 'edge-1',
            'fact' => 'KG fact about entity',
            'relation_type' => 'uses',
            'valid_at' => now(),
            'sourceEntity' => (object) ['name' => 'Entity A'],
            'targetEntity' => (object) ['name' => 'Entity B'],
        ];

        $vectorSearch = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $vectorSearch->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection([$vectorMemory]));

        $kgService = Mockery::mock(TemporalKnowledgeGraphService::class);
        $kgService->shouldReceive('search')
            ->once()
            ->andReturn(new Collection([$kgEdge]));

        $action = new UnifiedMemorySearchAction($vectorSearch, $kgService);

        $results = $action->execute(
            teamId: 'team-1',
            query: 'test query',
            agentId: 'agent-1',
        );

        $this->assertCount(2, $results);

        $types = $results->pluck('type')->toArray();
        $this->assertContains('memory', $types);
        $this->assertContains('kg_fact', $types);

        foreach ($results as $result) {
            $this->assertArrayHasKey('score', $result);
            $this->assertGreaterThan(0, $result['score']);
        }
    }

    public function test_rrf_score_calculation_is_correct(): void
    {
        config(['memory.unified_search.enabled' => true]);
        config(['memory.unified_search.vector_weight' => 1.0]);
        config(['memory.unified_search.keyword_weight' => 0.5]);
        config(['memory.unified_search.kg_weight' => 2.0]);
        config(['memory.unified_search.rrf_k' => 60]);

        $this->fakeEmbeddings();

        $vectorMemory = (object) [
            'id' => 'mem-1',
            'content' => 'Memory',
            'source_type' => 'execution',
            'agent_id' => 'agent-1',
            'effective_importance' => 0.5,
            'retrieval_count' => 0,
            'created_at' => now(),
            'confidence' => 1.0,
            'metadata' => [],
        ];

        $vectorSearch = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $vectorSearch->shouldReceive('execute')
            ->once()
            ->andReturn(new Collection([$vectorMemory]));

        $kgService = Mockery::mock(TemporalKnowledgeGraphService::class);
        $kgService->shouldReceive('search')
            ->once()
            ->andReturn(new Collection);

        $action = new UnifiedMemorySearchAction($vectorSearch, $kgService);

        $results = $action->execute(
            teamId: 'team-1',
            query: 'test',
            agentId: 'agent-1',
        );

        $this->assertCount(1, $results);

        // vector_weight / (rank + 1 + rrf_k) = 1.0 / (0 + 1 + 60) = 0.01639
        $expectedScore = 1.0 / (0 + 1 + 60);
        $this->assertEqualsWithDelta($expectedScore, $results->first()['score'], 0.001);
    }

    public function test_respects_top_k_limit(): void
    {
        config(['memory.unified_search.enabled' => true]);

        $this->fakeEmbeddings();

        $vectorResults = new Collection;
        for ($i = 0; $i < 5; $i++) {
            $vectorResults->push((object) [
                'id' => "mem-{$i}",
                'content' => "Memory {$i}",
                'source_type' => 'execution',
                'agent_id' => 'agent-1',
                'effective_importance' => 0.5,
                'retrieval_count' => 0,
                'created_at' => now(),
                'confidence' => 1.0,
                'metadata' => [],
            ]);
        }

        $vectorSearch = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $vectorSearch->shouldReceive('execute')->andReturn($vectorResults);

        $kgService = Mockery::mock(TemporalKnowledgeGraphService::class);
        $kgService->shouldReceive('search')->andReturn(new Collection);

        $action = new UnifiedMemorySearchAction($vectorSearch, $kgService);

        $results = $action->execute(
            teamId: 'team-1',
            query: 'test',
            agentId: 'agent-1',
            topK: 3,
        );

        $this->assertCount(3, $results);
    }
}
