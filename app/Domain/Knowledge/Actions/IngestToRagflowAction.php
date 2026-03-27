<?php

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Jobs\RagflowSyncStatusJob;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Infrastructure\RAGFlow\RAGFlowClient;
use Illuminate\Support\Facades\Log;

class IngestToRagflowAction
{
    public function __construct(
        private readonly RAGFlowClient $client,
    ) {}

    /**
     * Upload content to RAGFlow, trigger DeepDoc parsing, then poll status async.
     *
     * Ensures the dataset exists (creates one if needed), uploads the document,
     * triggers parsing, and dispatches RagflowSyncStatusJob to poll completion.
     */
    public function execute(
        KnowledgeBase $knowledgeBase,
        string $content,
        string $sourceName = 'manual',
        string $mimeType = 'text/plain',
    ): void {
        // Ensure dataset exists on RAGFlow side
        $datasetId = $this->ensureDataset($knowledgeBase);

        // Upload document
        $document = $this->client->uploadDocument(
            datasetId: $datasetId,
            filename: $sourceName,
            content: $content,
            mimeType: $mimeType,
        );

        $documentId = $document['id'] ?? null;

        if (! $documentId) {
            Log::error('RAGFlow upload returned no document ID', [
                'knowledge_base_id' => $knowledgeBase->id,
                'source_name' => $sourceName,
            ]);
            $knowledgeBase->markError();

            return;
        }

        // Trigger async parsing
        $this->client->parseDocuments($datasetId, [$documentId]);

        $knowledgeBase->update(['ragflow_last_synced_at' => now()]);

        Log::info('RAGFlow document upload triggered', [
            'knowledge_base_id' => $knowledgeBase->id,
            'dataset_id' => $datasetId,
            'document_id' => $documentId,
            'source_name' => $sourceName,
        ]);

        // Poll parse status asynchronously
        RagflowSyncStatusJob::dispatch($knowledgeBase->id, $datasetId, $documentId)
            ->onQueue('ai-calls');
    }

    private function ensureDataset(KnowledgeBase $knowledgeBase): string
    {
        if ($knowledgeBase->ragflow_dataset_id) {
            return $knowledgeBase->ragflow_dataset_id;
        }

        $dataset = $this->client->createDataset(
            name: $knowledgeBase->name,
            chunkMethod: $knowledgeBase->ragflow_chunk_method ?? config('ragflow.chunk_method_default', 'general'),
        );

        $datasetId = $dataset['id'] ?? $dataset['dataset_id'] ?? null;

        if (! $datasetId) {
            throw new \RuntimeException('RAGFlow createDataset returned no ID');
        }

        $knowledgeBase->update(['ragflow_dataset_id' => $datasetId]);

        Log::info('RAGFlow dataset created', [
            'knowledge_base_id' => $knowledgeBase->id,
            'dataset_id' => $datasetId,
        ]);

        return $datasetId;
    }
}
