<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Models\Memory;
use App\Domain\Memory\Services\DocumentTextExtractor;
use App\Infrastructure\Storage\TenantStorageManager;
use Illuminate\Http\UploadedFile;

class UploadKnowledgeDocumentAction
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly StoreMemoryAction $storeMemory,
        private readonly TenantStorageManager $storage,
    ) {}

    /**
     * Upload a document, extract text, chunk + embed, and store as Memory records.
     *
     * @return Memory[]
     */
    public function execute(
        string $teamId,
        ?string $agentId,
        UploadedFile $file,
        ?string $projectId = null,
    ): array {
        $text = $this->extractor->extract(
            $file->getRealPath(),
            $file->getMimeType(),
        );

        if (empty(trim($text))) {
            throw new \RuntimeException('Could not extract text from the uploaded file.');
        }

        // Persist the original so a future re-embed (different model/chunking)
        // does not require the user to re-upload.
        $storageKey = $this->storage->put(
            $file,
            'knowledge',
            TenantStorageManager::VISIBILITY_PRIVATE,
            $teamId,
        );

        return $this->storeMemory->execute(
            teamId: $teamId,
            agentId: $agentId,
            content: $text,
            sourceType: 'document',
            projectId: $projectId,
            metadata: [
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'storage_key' => $storageKey,
            ],
        );
    }
}
