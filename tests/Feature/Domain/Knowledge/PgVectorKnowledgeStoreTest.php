<?php

namespace Tests\Feature\Domain\Knowledge;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Models\KnowledgeChunk;
use App\Domain\Knowledge\Services\PgVectorKnowledgeStore;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\SearchRequest;
use Tests\TestCase;

/**
 * pgvector store on neuron-ai 4 (SearchRequest, FilterExpression, accessor-only
 * Document). Postgres-only: run with DB_CONNECTION=pgsql.
 */
class PgVectorKnowledgeStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $kbId;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Postgres-only (pgvector): run with DB_CONNECTION=pgsql.');
        }

        $owner = User::factory()->create();
        $team = Team::create(['name' => 'KB', 'slug' => 'kb-'.Str::lower(Str::random(6)), 'owner_id' => $owner->id, 'settings' => []]);
        $this->kbId = KnowledgeBase::withoutGlobalScopes()->create(['team_id' => $team->id, 'name' => 'Docs', 'status' => 'ready'])->id;
    }

    /** @return list<float> a 1536-dim unit vector pointing at axis $axis */
    private function vector(int $axis): array
    {
        $v = array_fill(0, 1536, 0.0);
        $v[$axis] = 1.0;

        return $v;
    }

    private function doc(string $text, string $type, string $name, int $axis): Document
    {
        return (new Document($text))->setSourceType($type)->setSourceName($name)->setEmbedding($this->vector($axis));
    }

    public function test_add_search_filter_and_delete(): void
    {
        $store = new PgVectorKnowledgeStore($this->kbId, topK: 5);
        $store->addDocuments([
            $this->doc('alpha', 'file', 'a.md', 0),
            $this->doc('beta', 'file', 'b.md', 1),
            $this->doc('gamma', 'url', 'c.html', 2),
        ]);

        // Nearest first, scores and accessors populated.
        $hits = $store->search(new SearchRequest($this->vector(1)));
        $this->assertSame('beta', $hits[0]->getContent());
        $this->assertSame('b.md', $hits[0]->getSourceName());
        $this->assertEqualsWithDelta(1.0, $hits[0]->getScore(), 1e-6);

        // Compiled SQL filter.
        $files = $store->search(new SearchRequest($this->vector(2), Filter::where('sourceType', 'file')));
        $this->assertEqualsCanonicalizing(['alpha', 'beta'], array_map(fn (Document $d) => $d->getContent(), $files));

        // Evaluator path (IN is not compiled to SQL).
        $either = $store->search(new SearchRequest($this->vector(0), Filter::in('sourceName', ['a.md', 'c.html'])));
        $this->assertEqualsCanonicalizing(['alpha', 'gamma'], array_map(fn (Document $d) => $d->getContent(), $either));

        // topK from the request wins.
        $this->assertCount(1, $store->search(new SearchRequest($this->vector(0), topK: 1)));

        // Delete by source (SQL path) and by an IN expression (evaluator path).
        $store->delete(Filter::where('sourceType', 'file')->where('sourceName', 'a.md'));
        $this->assertSame(['b.md', 'c.html'], KnowledgeChunk::where('knowledge_base_id', $this->kbId)->orderBy('source_name')->pluck('source_name')->all());

        $store->delete(Filter::in('sourceName', ['b.md', 'c.html']));
        $this->assertSame(0, KnowledgeChunk::where('knowledge_base_id', $this->kbId)->count());
    }
}
