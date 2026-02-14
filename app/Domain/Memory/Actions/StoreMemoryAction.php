<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class StoreMemoryAction
{
    /**
     * Chunk content, generate embeddings, and store as Memory records.
     *
     * @return Memory[]
     */
    public function execute(
        string $teamId,
        string $agentId,
        string $content,
        string $sourceType,
        ?string $projectId = null,
        ?string $sourceId = null,
        array $metadata = [],
    ): array {
        if (! config('memory.enabled', true)) {
            return [];
        }

        if (empty(trim($content))) {
            return [];
        }

        $chunks = $this->chunkContent($content);
        $memories = [];

        foreach ($chunks as $chunk) {
            try {
                $embedding = $this->generateEmbedding($chunk);

                $memory = Memory::create([
                    'team_id' => $teamId,
                    'agent_id' => $agentId,
                    'project_id' => $projectId,
                    'content' => $chunk,
                    'embedding' => $embedding,
                    'metadata' => $metadata,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                ]);

                $memories[] = $memory;
            } catch (\Throwable $e) {
                Log::warning('StoreMemoryAction: Failed to store memory chunk', [
                    'agent_id' => $agentId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $memories;
    }

    /**
     * Split content into chunks of configurable max size.
     *
     * @return string[]
     */
    private function chunkContent(string $content): array
    {
        $maxSize = config('memory.max_chunk_size', 2000);

        if (strlen($content) <= $maxSize) {
            return [$content];
        }

        $chunks = [];
        $paragraphs = preg_split('/\n\n+/', $content);
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            if (strlen($currentChunk) + strlen($paragraph) + 2 > $maxSize) {
                if ($currentChunk !== '') {
                    $chunks[] = trim($currentChunk);
                }
                // If a single paragraph exceeds max size, split by sentences
                if (strlen($paragraph) > $maxSize) {
                    $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
                    $currentChunk = '';
                    foreach ($sentences as $sentence) {
                        if (strlen($currentChunk) + strlen($sentence) + 1 > $maxSize) {
                            if ($currentChunk !== '') {
                                $chunks[] = trim($currentChunk);
                            }
                            $currentChunk = $sentence;
                        } else {
                            $currentChunk .= ($currentChunk ? ' ' : '').$sentence;
                        }
                    }
                } else {
                    $currentChunk = $paragraph;
                }
            } else {
                $currentChunk .= ($currentChunk ? "\n\n" : '').$paragraph;
            }
        }

        if (trim($currentChunk) !== '') {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Generate embedding vector using PrismPHP.
     *
     * @return string Vector string for pgvector (e.g. "[0.1,0.2,...]")
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
