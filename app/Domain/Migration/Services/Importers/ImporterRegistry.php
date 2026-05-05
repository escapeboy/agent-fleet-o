<?php

namespace App\Domain\Migration\Services\Importers;

use App\Domain\Migration\Enums\MigrationEntityType;

final class ImporterRegistry
{
    public function __construct(
        private readonly ContactImporter $contactImporter,
    ) {}

    public function resolve(MigrationEntityType $type): EntityImporter
    {
        return match ($type) {
            MigrationEntityType::Contact => $this->contactImporter,
        };
    }

    /**
     * @return list<EntityImporter>
     */
    public function all(): array
    {
        return [$this->contactImporter];
    }
}
