<?php

namespace App\Domain\Memory\Actions;

use App\Domain\Memory\Services\DocumentTextExtractor;
use Illuminate\Http\UploadedFile;

class UploadKnowledgeDocumentAction
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly StoreMemoryAction $storeMemory,
    ) {}

    /**
     * Upload a document, extract text, chunk + embed, and store as Memory records.
     *
     * @return \App\Domain\Memory\Models\Memory[]
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

        return $this->storeMemory->execute(
            teamId: $teamId,
            agentId: $agentId ?? 'team-knowledge',
            content: $text,
            sourceType: 'document',
            projectId: $projectId,
            metadata: [
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ],
        );
    }
}
