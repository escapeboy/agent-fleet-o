<?php

namespace App\Infrastructure\AI\Services;

use Prism\Prism\Facades\Prism;

class EmbeddingService
{
    public function __construct(
        private readonly string $provider = 'openai',
        private readonly string $model = 'text-embedding-3-small',
    ) {}

    /**
     * Generate an embedding vector for the given text.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $response = Prism::embeddings()
            ->using($this->provider, $this->model)
            ->fromInput($text)
            ->asEmbeddings();

        return $response->embeddings[0]->embedding;
    }

    /**
     * Format a float[] embedding as a pgvector literal string, e.g. "[0.1,0.2,...]".
     *
     * @param  float[]  $embedding
     */
    public function formatForPgvector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
