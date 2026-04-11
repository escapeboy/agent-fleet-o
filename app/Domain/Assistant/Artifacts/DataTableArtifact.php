<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

final class DataTableArtifact extends BaseArtifact
{
    public const TYPE = 'data_table';

    private const MAX_COLUMNS = 8;

    private const MAX_ROWS = 50;

    private const MAX_CELL_CHARS = 200;

    private const MAX_TITLE_CHARS = 100;

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array<string, string>>  $rows
     */
    public function __construct(
        public readonly string $title,
        public readonly array $columns,
        public readonly array $rows,
        public readonly string $sourceTool,
        public readonly bool $truncated,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function sourceTool(): ?string
    {
        return $this->sourceTool;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $sourceTool = StringSanitizer::clean($raw['source_tool'] ?? null, 64);
        if ($sourceTool === null || ! self::toolRanInTurn($sourceTool, $toolCallsInTurn)) {
            return null;
        }

        $title = StringSanitizer::cleanOrEmpty($raw['title'] ?? '', self::MAX_TITLE_CHARS);

        $columns = [];
        $columnDefs = array_slice($raw['columns'] ?? [], 0, self::MAX_COLUMNS);
        foreach ($columnDefs as $col) {
            if (! is_array($col)) {
                continue;
            }
            $key = StringSanitizer::slugify($col['key'] ?? null, 40);
            $label = StringSanitizer::clean($col['label'] ?? $key, 80);
            if ($key === null || $label === null) {
                continue;
            }
            $columns[] = ['key' => $key, 'label' => $label];
        }

        if ($columns === []) {
            return null;
        }

        $allowedKeys = array_column($columns, 'key');

        $rawRows = $raw['rows'] ?? [];
        if (! is_array($rawRows)) {
            return null;
        }

        $truncated = count($rawRows) > self::MAX_ROWS;
        $rowsToUse = array_slice($rawRows, 0, self::MAX_ROWS);

        $rows = [];
        foreach ($rowsToUse as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = [];
            foreach ($allowedKeys as $key) {
                $cells[$key] = StringSanitizer::cleanOrEmpty($row[$key] ?? '', self::MAX_CELL_CHARS);
            }
            $rows[] = $cells;
        }

        return new self(
            title: $title,
            columns: $columns,
            rows: $rows,
            sourceTool: $sourceTool,
            truncated: $truncated,
        );
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'source_tool' => $this->sourceTool,
            'truncated' => $this->truncated,
        ];
    }
}
