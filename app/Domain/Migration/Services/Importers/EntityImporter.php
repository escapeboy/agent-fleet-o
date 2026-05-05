<?php

namespace App\Domain\Migration\Services\Importers;

use App\Domain\Migration\DTOs\ImportStats;

abstract class EntityImporter
{
    abstract public function entityType(): string;

    /**
     * Attributes the importer understands. Keys are canonical attribute names;
     * values are human labels used in schema proposal prompts.
     *
     * @return array<string, string>
     */
    abstract public function supportedAttributes(): array;

    /**
     * Import a single row given the confirmed mapping.
     * Returns an outcome tag: 'created' | 'updated' | 'skipped' | 'failed'.
     * On 'failed', the importer is responsible for logging the reason via the
     * $onError callback — the caller treats the outcome as the authoritative signal.
     *
     * @param  array<string, string>  $row  raw CSV row keyed by column header
     * @param  array<string, string>  $mapping  column header → canonical attribute name
     * @param  callable(string): void  $onError  appends an error message for this row
     */
    abstract public function importRow(
        string $teamId,
        array $row,
        array $mapping,
        callable $onError,
    ): string;

    /**
     * Apply importRow across every row, accumulating stats.
     *
     * @param  list<array<string, string>>  $rows
     * @param  array<string, string>  $mapping
     * @param  callable(int, string): void  $onError  receives (row_index, message) for bounded error collection
     */
    public function import(string $teamId, array $rows, array $mapping, callable $onError): ImportStats
    {
        $stats = new ImportStats(total: count($rows));
        foreach ($rows as $index => $row) {
            $outcome = $this->importRow(
                $teamId,
                $row,
                $mapping,
                fn (string $msg) => $onError($index, $msg),
            );
            match ($outcome) {
                'created' => $stats->created++,
                'updated' => $stats->updated++,
                'skipped' => $stats->skipped++,
                'failed' => $stats->failed++,
                default => $stats->skipped++,
            };
        }

        return $stats;
    }
}
