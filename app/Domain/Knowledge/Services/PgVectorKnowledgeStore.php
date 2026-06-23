<?php

namespace App\Domain\Knowledge\Services;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Models\KnowledgeChunk;
use App\Domain\Shared\Services\UserGroupResolver;
use Illuminate\Support\Facades\DB;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Ramsey\Uuid\Uuid;

/**
 * PostgreSQL pgvector implementation of Neuron's VectorStoreInterface.
 * Uses the knowledge_chunks table with HNSW cosine index.
 */
class PgVectorKnowledgeStore implements VectorStoreInterface
{
    public function __construct(
        private readonly string $knowledgeBaseId,
        private readonly int $topK = 5,
    ) {}

    public function addDocument(Document $document): VectorStoreInterface
    {
        KnowledgeChunk::create([
            'id' => Uuid::uuid7()->toString(),
            'knowledge_base_id' => $this->knowledgeBaseId,
            'content' => $document->content,
            'source_name' => $document->sourceName,
            'source_type' => $document->sourceType,
            'metadata' => $document->metadata,
            'embedding' => '['.implode(',', $document->embedding).']',
        ]);

        return $this;
    }

    /** @param Document[] $documents */
    public function addDocuments(array $documents): VectorStoreInterface
    {
        foreach ($documents as $document) {
            $this->addDocument($document);
        }

        return $this;
    }

    /**
     * @param  float[]  $embedding
     * @return Document[]
     */
    public function similaritySearch(array $embedding): iterable
    {
        $vector = '['.implode(',', $embedding).']';

        // Source-ACL filter (dark-shipped): when enabled, restrict chunks to those
        // visible to the requesting user's groups; empty clause when disabled.
        $gate = app(SourceAclGate::class);
        $groups = $gate->enabled()
            ? app(UserGroupResolver::class)->groupsFor(auth()->user(), $this->resolveTeamId())
            : [];
        $acl = $gate->sqlClause($groups);

        $rows = DB::select(
            'SELECT id, content, source_name, source_type, metadata,
                    1 - (embedding <=> ?) AS score
             FROM knowledge_chunks
             WHERE knowledge_base_id = ?
               AND embedding IS NOT NULL'.$acl['sql'].'
             ORDER BY embedding <=> ?
             LIMIT ?',
            array_merge([$vector, $this->knowledgeBaseId], $acl['bindings'], [$vector, $this->topK]),
        );

        return array_map(function (object $row): Document {
            $doc = new Document($row->content);
            $doc->id = $row->id;
            $doc->sourceName = $row->source_name;
            $doc->sourceType = $row->source_type;
            $doc->metadata = json_decode($row->metadata, true) ?? [];
            $doc->setScore((float) $row->score);

            return $doc;
        }, $rows);
    }

    private function resolveTeamId(): ?string
    {
        return KnowledgeBase::withoutGlobalScopes()
            ->whereKey($this->knowledgeBaseId)
            ->value('team_id');
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $query = KnowledgeChunk::where('knowledge_base_id', $this->knowledgeBaseId)
            ->where('source_type', $sourceType);

        if ($sourceName !== null) {
            $query->where('source_name', $sourceName);
        }

        $query->delete();

        return $this;
    }
}
