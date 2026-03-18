<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Jobs\IngestDocumentJob;
use App\Domain\Knowledge\Models\KnowledgeBase;

class IngestDocumentAction
{
    /**
     * Dispatch an async ingestion job for the given content.
     *
     * @param  string  $content  Raw text content to ingest (pre-loaded)
     * @param  string  $sourceName  Display name of the source (e.g. filename, URL)
     * @param  string  $sourceType  'file', 'url', or 'text'
     */
    public function execute(
        KnowledgeBase $knowledgeBase,
        string $content,
        string $sourceName = 'manual',
        string $sourceType = 'text',
        bool $reindex = false,
    ): void {
        IngestDocumentJob::dispatch(
            $knowledgeBase->id,
            $content,
            $sourceName,
            $sourceType,
            $reindex,
        )->onQueue('ai-calls');
    }
}
