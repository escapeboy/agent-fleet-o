<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Domain\Knowledge\Services\KnowledgeBaseRAGFactory;
use App\Domain\KnowledgeGraph\Services\TemporalKnowledgeGraphService;
use Closure;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

/**
 * Injects relevant context into the agent's system prompt from two sources:
 * 1. Temporal knowledge graph facts (entity relationships)
 * 2. RAG knowledge base chunks (if the agent has a bound knowledge base)
 */
class InjectKnowledgeGraphContext
{
    public function __construct(
        private readonly TemporalKnowledgeGraphService $kgService,
        private readonly KnowledgeBaseRAGFactory $ragFactory,
    ) {}

    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        $inputText = is_array($ctx->input)
            ? ($ctx->input['task'] ?? $ctx->input['content'] ?? $ctx->input['query'] ?? implode(' ', array_filter($ctx->input, 'is_string')))
            : (string) $ctx->input;

        // If the scout phase identified targeted queries, prepend them to focus the embedding search
        if (! empty($ctx->scoutQueries)) {
            $inputText = implode(' ', $ctx->scoutQueries).' '.$inputText;
        }

        if (mb_strlen(trim($inputText)) < 10) {
            return $next($ctx);
        }

        // 1. Temporal knowledge graph context
        try {
            $queryEmbedding = $this->generateEmbedding($inputText);

            if ($queryEmbedding) {
                $kgContext = $this->kgService->buildContext($ctx->teamId, $queryEmbedding);

                if ($kgContext) {
                    $ctx->systemPromptParts[] = $kgContext;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('InjectKnowledgeGraphContext: KG context failed', [
                'agent_id' => $ctx->agent->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. RAG knowledge base context (from all linked knowledge bases)
        $knowledgeBases = $ctx->agent->knowledgeBases()->get();

        // Backward compat: include legacy single FK if not already in the pivot
        $legacyKbId = $ctx->agent->knowledge_base_id;
        if ($legacyKbId && ! $knowledgeBases->contains('id', $legacyKbId)) {
            $legacyKb = KnowledgeBase::find($legacyKbId);
            if ($legacyKb) {
                $knowledgeBases->push($legacyKb);
            }
        }

        foreach ($knowledgeBases as $kb) {
            if (! $kb->isReady()) {
                continue;
            }

            try {
                $chunks = $this->ragFactory->search(
                    knowledgeBaseId: $kb->id,
                    query: $inputText,
                    topK: 5,
                );

                if (! empty($chunks)) {
                    $parts = array_map(
                        fn ($c) => "Source: {$c['source']}\n{$c['content']}",
                        $chunks,
                    );
                    $ctx->systemPromptParts[] = "## Knowledge: {$kb->name}\n\n".implode("\n\n---\n\n", $parts);
                }
            } catch (\Throwable $e) {
                Log::warning('InjectKnowledgeGraphContext: RAG context failed', [
                    'agent_id' => $ctx->agent->id,
                    'knowledge_base_id' => $kb->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $next($ctx);
    }

    private function generateEmbedding(string $text): ?string
    {
        try {
            $model = config('memory.embedding_model', 'text-embedding-3-small');

            $response = Prism::embeddings()
                ->using(config('memory.embedding_provider', 'openai'), $model)
                ->fromInput(mb_substr($text, 0, 1000))
                ->asEmbeddings();

            $vector = $response->embeddings[0]->embedding;

            return '['.implode(',', $vector).']';
        } catch (\Throwable) {
            return null;
        }
    }
}
