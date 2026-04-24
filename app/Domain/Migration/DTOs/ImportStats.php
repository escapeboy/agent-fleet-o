<?php

namespace App\Domain\Migration\DTOs;

final class ImportStats
{
    public function __construct(
        public int $total = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            total: (int) ($data['total'] ?? 0),
            created: (int) ($data['created'] ?? 0),
            updated: (int) ($data['updated'] ?? 0),
            skipped: (int) ($data['skipped'] ?? 0),
            failed: (int) ($data['failed'] ?? 0),
        );
    }
}
