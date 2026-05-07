<?php

namespace App\Domain\Chatbot\Services;

use App\Domain\Chatbot\Contracts\KnowledgeGraphContextProviderInterface;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Shared\Models\Team;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Prism;

class ChatbotKnowledgeGraphContextProvider implements KnowledgeGraphContextProviderInterface
{
    public function retrieveContext(string $query, ?string $teamId = null): ?string
    {
        $teamId ??= Team::first()?->id;

        if (! $teamId) {
            return null;
        }

        try {
            $queryEmbedding = $this->generateEmbedding($query);

            if (! $queryEmbedding) {
                return null;
            }

            $threshold = (float) config('chat.knowledge_graph.similarity_threshold', 0.3);
            $limit = (int) config('chat.knowledge_graph.max_results', 5);

            // Direct query — TemporalKnowledgeGraphService::search() has a HAVING bug on PostgreSQL
            $facts = KgEdge::withoutGlobalScopes()
                ->select('kg_edges.*')
                ->selectRaw('1 - (fact_embedding <=> ?) AS similarity', [$queryEmbedding])
                ->where('team_id', $teamId)
                ->whereNull('invalid_at')
                ->whereNotNull('fact_embedding')
                ->whereRaw('1 - (fact_embedding <=> ?) >= ?', [$queryEmbedding, $threshold])
                ->with(['sourceEntity', 'targetEntity'])
                ->orderByDesc('similarity')
                ->limit($limit)
                ->get();

            if ($facts->isEmpty()) {
                return null;
            }

            $lines = $facts->map(function ($edge) {
                $source = $edge->sourceEntity->name ?? 'Unknown';
                $target = $edge->targetEntity->name ?? 'Unknown';

                return "- {$edge->fact} [{$source} → {$target}]";
            })->join("\n");

            return "## Knowledge Graph Facts\n{$lines}";
        } catch (\Throwable $e) {
            Log::warning('ChatbotKnowledgeGraphContextProvider: retrieval failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function generateEmbedding(string $text): ?string
    {
        try {
            $model = config('memory.embedding_model', 'text-embedding-3-small');

            $response = app(Prism::class)->embeddings()
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
