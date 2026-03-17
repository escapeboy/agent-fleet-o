<?php

namespace App\Domain\Knowledge\Services;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;
use Prism\Prism\Facades\Prism;

/**
 * Implements Neuron's EmbeddingsProviderInterface using PrismPHP's embeddings API.
 * Uses OpenAI text-embedding-3-small (1536 dimensions) to match the memories table schema.
 */
class PrismEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    private const EMBEDDING_PROVIDER = 'openai';

    private const EMBEDDING_MODEL = 'text-embedding-3-small';

    private const MAX_INPUT_CHARS = 8000;

    /**
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $text = mb_substr(trim($text), 0, self::MAX_INPUT_CHARS);

        $response = Prism::embeddings()
            ->using(self::EMBEDDING_PROVIDER, self::EMBEDDING_MODEL)
            ->fromInput($text)
            ->asEmbeddings();

        return $response->embeddings[0]->embedding;
    }

    public function embedDocument(Document $document): Document
    {
        $text = $document->content;
        $document->embedding = $this->embedText($text);

        return $document;
    }
}
