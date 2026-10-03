<?php

namespace App\Domain\Knowledge\Services;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Models\KnowledgeChunk;
use App\Domain\Shared\Services\UserGroupResolver;
use Illuminate\Support\Facades\DB;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\Filter\FilterEvaluator;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\HasDocumentSchema;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Ramsey\Uuid\Uuid;

/**
 * PostgreSQL pgvector implementation of Neuron's VectorStoreInterface (neuron-ai 4).
 * Uses the knowledge_chunks table with HNSW cosine index.
 *
 * Filters: neuron 4 only allows sourceType / sourceName unless a DocumentSchema
 * declares more. A plain AND of equalities on those two fields is compiled to SQL;
 * anything else is evaluated with Neuron's own FilterEvaluator.
 */
class PgVectorKnowledgeStore implements VectorStoreInterface
{
    use HasDocumentSchema;

    private const FILTER_COLUMNS = ['sourceType' => 'source_type', 'sourceName' => 'source_name'];

    public function __construct(
        private readonly string $knowledgeBaseId,
        private readonly int $topK = 5,
    ) {
        $this->initializeSchema(null);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        $this->validateDocument($document);

        KnowledgeChunk::create([
            'id' => Uuid::uuid7()->toString(),
            'knowledge_base_id' => $this->knowledgeBaseId,
            'content' => $document->getContent(),
            'source_name' => $document->getSourceName(),
            'source_type' => $document->getSourceType(),
            'metadata' => $document->getMetadata(),
            'embedding' => '['.implode(',', $document->getEmbedding() ?? []).']',
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
     * @return Document[]
     */
    public function search(SearchRequest $request): iterable
    {
        $vector = '['.implode(',', $request->embedding).']';
        $limit = $request->topK ?? $this->topK;

        // Source-ACL filter (dark-shipped): when enabled, restrict chunks to those
        // visible to the requesting user's groups; empty clause when disabled.
        $gate = app(SourceAclGate::class);
        $groups = $gate->enabled()
            ? app(UserGroupResolver::class)->groupsFor(auth()->user(), $this->resolveTeamId())
            : [];
        $acl = $gate->sqlClause($groups);

        $filterSql = '';
        $filterBindings = [];
        $postFilter = null;
        if ($request->filters instanceof FilterExpression) {
            $this->validateFilters($request->filters);
            $compiled = $this->compileFilter($request->filters);
            if ($compiled !== null) {
                [$filterSql, $filterBindings] = $compiled;
            } else {
                $postFilter = $request->filters;
            }
        }

        $rows = DB::select(
            'SELECT id, content, source_name, source_type, metadata,
                    1 - (embedding <=> ?) AS score
             FROM knowledge_chunks
             WHERE knowledge_base_id = ?
               AND embedding IS NOT NULL'.$acl['sql'].$filterSql.'
             ORDER BY embedding <=> ?
             LIMIT ?',
            array_merge([$vector, $this->knowledgeBaseId], $acl['bindings'], $filterBindings, [$vector, $limit]),
        );

        $documents = array_map(fn (object $row): Document => (new Document($row->content))
            ->setId($row->id)
            ->setSourceName($row->source_name)
            ->setSourceType($row->source_type)
            ->setMetadata(json_decode((string) $row->metadata, true) ?? [])
            ->setScore((float) $row->score), $rows);

        if ($postFilter === null) {
            return $documents;
        }

        $evaluator = new FilterEvaluator;

        return array_values(array_filter($documents, fn (Document $document): bool => $evaluator->matchesDocument($postFilter, $document)));
    }

    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->validateFilters($filters);

        $query = KnowledgeChunk::where('knowledge_base_id', $this->knowledgeBaseId);
        $compiled = $this->compileFilter($filters);

        if ($compiled !== null) {
            $query->whereRaw('true'.$compiled[0], $compiled[1])->delete();

            return $this;
        }

        $evaluator = new FilterEvaluator;
        $ids = $query->get(['id', 'source_type', 'source_name', 'metadata'])
            ->filter(fn (KnowledgeChunk $chunk): bool => $evaluator->matchesDocument($filters, (new Document(''))
                ->setSourceType((string) $chunk->source_type)
                ->setSourceName((string) $chunk->source_name)
                ->setMetadata((array) ($chunk->metadata ?? []))))
            ->pluck('id');

        KnowledgeChunk::where('knowledge_base_id', $this->knowledgeBaseId)->whereIn('id', $ids)->delete();

        return $this;
    }

    /**
     * Compile `field = value AND …` on sourceType/sourceName to SQL; null when the
     * expression uses anything else.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private function compileFilter(FilterExpression $filters): ?array
    {
        $tree = $filters->toArray();
        $conditions = ($tree['operator'] ?? null) === 'and' ? ($tree['conditions'] ?? []) : [$tree];

        $sql = '';
        $bindings = [];
        foreach ($conditions as $condition) {
            $column = self::FILTER_COLUMNS[$condition['field'] ?? ''] ?? null;
            if (($condition['operator'] ?? null) !== 'eq' || $column === null || ! is_scalar($condition['value'] ?? null)) {
                return null;
            }
            $sql .= " AND {$column} = ?";
            $bindings[] = $condition['value'];
        }

        return $sql === '' ? null : [$sql, $bindings];
    }

    private function resolveTeamId(): ?string
    {
        return KnowledgeBase::withoutGlobalScopes()
            ->whereKey($this->knowledgeBaseId)
            ->value('team_id');
    }
}
