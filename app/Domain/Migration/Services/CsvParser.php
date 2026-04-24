<?php

namespace App\Domain\Migration\Services;

final class CsvParser
{
    private const MAX_BYTES = 5_242_880; // 5 MB hard cap — protect the queue worker.

    /**
     * Parse CSV payload into headers + rows.
     *
     * @return array{headers: list<string>, rows: list<array<string, string>>, row_count: int}
     */
    public function parse(string $payload, int $maxRows = PHP_INT_MAX): array
    {
        if ($payload === '') {
            return ['headers' => [], 'rows' => [], 'row_count' => 0];
        }
        if (strlen($payload) > self::MAX_BYTES) {
            throw new \RuntimeException('CSV payload exceeds 5 MB limit');
        }

        $payload = $this->stripBom($payload);
        $lines = preg_split('/\r\n|\r|\n/', $payload) ?: [];
        $lines = array_values(array_filter($lines, fn ($l) => $l !== ''));
        if ($lines === []) {
            return ['headers' => [], 'rows' => [], 'row_count' => 0];
        }

        $delimiter = $this->sniffDelimiter($lines[0]);
        $headers = $this->normaliseHeaders(str_getcsv($lines[0], $delimiter, '"', '\\'));
        if ($headers === []) {
            return ['headers' => [], 'rows' => [], 'row_count' => 0];
        }

        $rows = [];
        $rowCount = 0;
        $total = count($lines);
        for ($i = 1; $i < $total; $i++) {
            $values = str_getcsv($lines[$i], $delimiter, '"', '\\');
            if (count($values) === 1 && ($values[0] === null || trim($values[0]) === '')) {
                continue;
            }
            $rowCount++;
            if ($rowCount > $maxRows) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $header) {
                $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows, 'row_count' => $rowCount];
    }

    private function stripBom(string $payload): string
    {
        if (str_starts_with($payload, "\xEF\xBB\xBF")) {
            return substr($payload, 3);
        }

        return $payload;
    }

    private function sniffDelimiter(string $headerLine): string
    {
        $candidates = [',', ';', "\t", '|'];
        $best = ',';
        $bestCount = 0;
        foreach ($candidates as $candidate) {
            $count = substr_count($headerLine, $candidate);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, string|null>  $raw
     * @return list<string>
     */
    private function normaliseHeaders(array $raw): array
    {
        $seen = [];
        $result = [];
        foreach ($raw as $header) {
            $clean = trim((string) ($header ?? ''));
            if ($clean === '') {
                continue;
            }
            $key = $clean;
            $suffix = 1;
            while (isset($seen[$key])) {
                $suffix++;
                $key = $clean.'_'.$suffix;
            }
            $seen[$key] = true;
            $result[] = $key;
        }

        return $result;
    }
}
