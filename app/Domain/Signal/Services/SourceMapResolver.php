<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Models\SourceMap;
use Illuminate\Support\Facades\Cache;

class SourceMapResolver
{
    private const VLQ_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

    /**
     * Resolve a stack frame to original source location.
     *
     * @param  array{file: string, line: int, column: int, function: string|null}  $frame
     * @return array{file: string, line: int, column: int, function: string|null, isProjectCode: bool}|null
     */
    public function resolve(string $teamId, string $project, string $release, array $frame): ?array
    {
        $mapData = $this->loadMap($teamId, $project, $release);
        if ($mapData === null) {
            return null;
        }

        $mappings = $this->parseMappings($mapData);
        $sources = $mapData['sources'] ?? [];
        $names = $mapData['names'] ?? [];

        // Source maps are 0-indexed lines; stack traces are 1-indexed
        $genLine = $frame['line'] - 1;
        $genCol = $frame['column'];

        $match = $this->findMapping($mappings, $genLine, $genCol);
        if ($match === null) {
            return null;
        }

        $originalFile = $sources[$match['src']] ?? null;
        if ($originalFile === null) {
            return null;
        }

        // Strip leading webpack:// or ./ prefixes
        $originalFile = preg_replace('#^(webpack:///|\./)#', '', $originalFile);

        $functionName = isset($match['name']) ? ($names[$match['name']] ?? $frame['function']) : $frame['function'];

        return [
            'file' => $originalFile,
            'line' => $match['origLine'] + 1,
            'column' => $match['origCol'],
            'function' => $functionName,
            'isProjectCode' => ! str_contains($originalFile, 'node_modules/'),
        ];
    }

    private function loadMap(string $teamId, string $project, string $release): ?array
    {
        $cacheKey = "source_map:{$teamId}:{$project}:{$release}";

        return Cache::remember($cacheKey, 1800, function () use ($teamId, $project, $release) {
            $record = SourceMap::where('team_id', $teamId)
                ->where('project', $project)
                ->where('release', $release)
                ->first();

            return $record?->map_data;
        });
    }

    /**
     * Parse VLQ-encoded mappings string into a lookup structure.
     *
     * @return array<int, array<int, array{genCol: int, src: int, origLine: int, origCol: int, name?: int}>>
     *                                                                                                       Indexed by [genLine][segment_idx]
     */
    private function parseMappings(array $mapData): array
    {
        $raw = $mapData['mappings'] ?? '';
        $result = [];

        $srcIdx = 0;
        $origLine = 0;
        $origCol = 0;
        $nameIdx = 0;

        foreach (explode(';', $raw) as $genLine => $lineStr) {
            $result[$genLine] = [];
            $genCol = 0;

            if ($lineStr === '') {
                continue;
            }

            foreach (explode(',', $lineStr) as $segStr) {
                if ($segStr === '') {
                    continue;
                }

                $values = $this->decodeVlq($segStr);
                if (count($values) < 1) {
                    continue;
                }

                $genCol += $values[0];

                $segment = ['genCol' => $genCol];

                if (count($values) >= 4) {
                    $srcIdx += $values[1];
                    $origLine += $values[2];
                    $origCol += $values[3];

                    $segment['src'] = $srcIdx;
                    $segment['origLine'] = $origLine;
                    $segment['origCol'] = $origCol;

                    if (count($values) >= 5) {
                        $nameIdx += $values[4];
                        $segment['name'] = $nameIdx;
                    }
                }

                $result[$genLine][] = $segment;
            }
        }

        return $result;
    }

    /**
     * Find the best matching segment for (genLine, genCol).
     *
     * @param  array<int, array<int, array>>  $mappings
     * @return array{src: int, origLine: int, origCol: int, name?: int}|null
     */
    private function findMapping(array $mappings, int $genLine, int $genCol): ?array
    {
        $segments = $mappings[$genLine] ?? [];
        if (empty($segments)) {
            return null;
        }

        // Find last segment with genCol <= target (floor match)
        $best = null;
        foreach ($segments as $segment) {
            if ($segment['genCol'] <= $genCol) {
                $best = $segment;
            } else {
                break;
            }
        }

        if ($best === null || ! isset($best['src'])) {
            return null;
        }

        return $best;
    }

    /**
     * Decode a VLQ-encoded string into an array of integers.
     *
     * @return int[]
     */
    private function decodeVlq(string $str): array
    {
        $values = [];
        $i = 0;
        $len = strlen($str);

        while ($i < $len) {
            $result = 0;
            $shift = 0;

            do {
                $char = $str[$i++] ?? null;
                if ($char === null) {
                    break;
                }

                $digit = strpos(self::VLQ_CHARS, $char);
                if ($digit === false) {
                    break;
                }

                $continuation = $digit & 0x20; // bit 5
                $digit &= 0x1F;                // lower 5 bits
                $result |= ($digit << $shift);
                $shift += 5;
            } while ($continuation);

            // LSB of result is the sign bit
            $values[] = ($result & 1) ? -(($result >> 1)) : ($result >> 1);
        }

        return $values;
    }
}
