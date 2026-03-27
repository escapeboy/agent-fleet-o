<?php

namespace App\Domain\Knowledge\Jobs;

use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Infrastructure\RAGFlow\Exceptions\RAGFlowException;
use App\Infrastructure\RAGFlow\RAGFlowClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Polls RAGFlow until a document's parse status is complete or fails.
 *
 * Dispatched by IngestToRagflowAction immediately after uploading a document.
 * Self-delays up to 20 attempts (10s each = 200s window).
 */
class RagflowSyncStatusJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 30;

    public function __construct(
        private readonly string $knowledgeBaseId,
        private readonly string $datasetId,
        private readonly string $documentId,
        private readonly int $attempt = 0,
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping($this->knowledgeBaseId)];
    }

    public function handle(RAGFlowClient $client): void
    {
        $kb = KnowledgeBase::find($this->knowledgeBaseId);
        if (! $kb) {
            return;
        }

        try {
            $doc = $client->getDocumentStatus($this->datasetId, $this->documentId);
            $status = $doc['run'] ?? $doc['status'] ?? 'UNSTART';

            Log::debug('RAGFlow parse status', [
                'knowledge_base_id' => $this->knowledgeBaseId,
                'document_id' => $this->documentId,
                'status' => $status,
                'attempt' => $this->attempt,
            ]);

            if ($status === 'DONE' || $status === 'done' || $status === 'complete') {
                $kb->markReady($kb->chunks()->count());
                Log::info('RAGFlow document parsing complete', [
                    'knowledge_base_id' => $this->knowledgeBaseId,
                    'document_id' => $this->documentId,
                    'chunk_count' => $doc['chunk_count'] ?? 0,
                ]);

                return;
            }

            if ($status === 'FAIL' || $status === 'fail' || $status === 'error') {
                $kb->markError();
                Log::error('RAGFlow document parsing failed', [
                    'knowledge_base_id' => $this->knowledgeBaseId,
                    'document_id' => $this->documentId,
                ]);

                return;
            }

            // Still processing — reschedule if retries remain
            if ($this->attempts() < $this->tries) {
                $this->release(10);
            } else {
                $kb->markError();
                Log::error('RAGFlow sync status timed out', [
                    'knowledge_base_id' => $this->knowledgeBaseId,
                    'document_id' => $this->documentId,
                ]);
            }
        } catch (RAGFlowException $e) {
            Log::warning('RAGFlow sync status check failed', [
                'knowledge_base_id' => $this->knowledgeBaseId,
                'error' => $e->getMessage(),
            ]);

            if ($this->attempts() < $this->tries) {
                $this->release(10);
            } else {
                $kb->markError();
            }
        }
    }
}
