<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Jobs\IngestDocumentJob;
use App\Domain\Knowledge\Models\KnowledgeBase;

class IngestDocumentAction
{
    public function __construct(
        private readonly IngestToRagflowAction $ragflowIngest,
    ) {}

    /**
     * Dispatch an async ingestion job for the given content.
     *
     * Routes to RAGFlow (DeepDoc parsing) when the knowledge base has
     * RAGFlow enabled; otherwise uses the pgvector NeuronAI pipeline.
     *
     * @param  string  $content  Raw text content to ingest (pre-loaded)
     * @param  string  $sourceName  Display name of the source (e.g. filename, URL)
     * @param  string  $sourceType  'file', 'url', or 'text'
     * @param  string  $mimeType  MIME type for RAGFlow upload (only used when RAGFlow is enabled)
     */
    public function execute(
        KnowledgeBase $knowledgeBase,
        string $content,
        string $sourceName = 'manual',
        string $sourceType = 'text',
        bool $reindex = false,
        string $mimeType = 'text/plain',
    ): void {
        if ($knowledgeBase->ragflow_enabled) {
            $this->ragflowIngest->execute($knowledgeBase, $content, $sourceName, $mimeType);

            return;
        }

        IngestDocumentJob::dispatch(
            $knowledgeBase->id,
            $content,
            $sourceName,
            $sourceType,
            $reindex,
        )->onQueue('ai-calls');
    }
}
