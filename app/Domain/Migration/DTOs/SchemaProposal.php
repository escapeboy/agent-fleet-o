<?php

namespace App\Domain\Migration\DTOs;

final class SchemaProposal
{
    /**
     * @param  array<string, ?string>  $columnMap  raw column header → target attribute (or null if unmapped)
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly string $entityType,
        public readonly array $columnMap,
        public readonly float $confidence,
        public readonly array $warnings = [],
    ) {}

    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'column_map' => $this->columnMap,
            'confidence' => $this->confidence,
            'warnings' => $this->warnings,
        ];
    }
}
