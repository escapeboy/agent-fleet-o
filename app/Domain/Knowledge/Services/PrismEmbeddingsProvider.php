<?php

namespace App\Domain\Knowledge\Services;

use App\Infrastructure\AI\Contracts\EmbeddingProviderInterface;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;

/**
 * Bridges Neuron's EmbeddingsProviderInterface to FleetQ's embedding seam
 * (EmbeddingProviderInterface → memory.embedding_driver). Keeps the 1536-dim
 * space that matches the memories/KB table schema.
 */
class PrismEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    private const MAX_INPUT_CHARS = 8000;

    /**
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $text = mb_substr(trim($text), 0, self::MAX_INPUT_CHARS);

        return app(EmbeddingProviderInterface::class)->embed($text);
    }

    public function embedDocument(Document $document): Document
    {
        $text = $document->content;
        $document->embedding = $this->embedText($text);

        return $document;
    }
}
