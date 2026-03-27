<?php

namespace App\Infrastructure\RAGFlow\DTOs;

readonly class RAGFlowChunk
{
    public function __construct(
        public string $id,
        public string $content,
        public float $similarity,
        public string $documentId,
        public string $documentName,
        public ?array $positions = null,
        public ?string $imagePath = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            content: $data['content'] ?? $data['chunk_text'] ?? '',
            similarity: (float) ($data['score'] ?? $data['similarity'] ?? 0.0),
            documentId: $data['doc_id'] ?? $data['document_id'] ?? '',
            documentName: $data['doc_name'] ?? $data['document_name'] ?? '',
            positions: $data['positions'] ?? null,
            imagePath: $data['img_id'] ?? $data['image_path'] ?? null,
        );
    }
}
