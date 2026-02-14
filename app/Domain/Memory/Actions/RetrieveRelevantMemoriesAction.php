<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class RetrieveRelevantMemoriesAction
{
    /**
     * Retrieve top-K relevant memories for an agent using cosine similarity.
     *
     * @return Collection<int, Memory>
     */
    public function execute(
        string $agentId,
        string $query,
        ?string $projectId = null,
        ?int $topK = null,
        ?float $threshold = null,
    ): Collection {
        if (! config('memory.enabled', true)) {
            return collect();
        }

        $topK = $topK ?? config('memory.top_k', 5);
        $threshold = $threshold ?? config('memory.similarity_threshold', 0.7);

        try {
            $queryEmbedding = $this->generateEmbedding($query);

            $query = Memory::withoutGlobalScopes()
                ->select('memories.*')
                ->selectRaw('1 - (embedding <=> ?) as similarity', [$queryEmbedding])
                ->where('agent_id', $agentId)
                ->havingRaw('1 - (embedding <=> ?) >= ?', [$queryEmbedding, $threshold])
                ->orderByRaw('embedding <=> ?', [$queryEmbedding]);

            if ($projectId) {
                $query->where(function ($q) use ($projectId) {
                    $q->where('project_id', $projectId)
                        ->orWhereNull('project_id');
                });
            }

            return $query->limit($topK)->get();
        } catch (\Throwable $e) {
            Log::warning('RetrieveRelevantMemoriesAction: Failed to retrieve memories', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Generate embedding vector for the query string.
     *
     * @return string Vector string for pgvector
     */
    private function generateEmbedding(string $text): string
    {
        $model = config('memory.embedding_model', 'text-embedding-3-small');

        $response = Prism::embeddings()
            ->using('openai', $model)
            ->fromInput($text)
            ->asEmbeddings();

        $vector = $response->embeddings[0]->embedding;

        return '['.implode(',', $vector).']';
    }
}
