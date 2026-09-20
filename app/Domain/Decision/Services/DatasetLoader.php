<?php

namespace App\Domain\Decision\Services;

use App\Domain\Decision\DTOs\DatasetCase;
use Generator;
use InvalidArgumentException;
use JsonException;

/**
 * Streams a JSONL dataset one case per line. Streaming rather than loading:
 * routing.jsonl is small today, but an eval that OOMs on a bigger dataset is a
 * harness nobody reaches for.
 */
final class DatasetLoader
{
    /**
     * @return Generator<int, DatasetCase>
     */
    public function load(string $path, ?string $split = null): Generator
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Dataset not found at [{$path}].");
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new InvalidArgumentException("Dataset at [{$path}] could not be opened.");
        }

        try {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                try {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new InvalidArgumentException("Line {$lineNumber} of [{$path}] is not valid JSON: ".$e->getMessage());
                }

                if (! is_array($row) || ! isset($row['id'], $row['state'], $row['questions'], $row['gold'])) {
                    throw new InvalidArgumentException("Line {$lineNumber} of [{$path}] is missing id, state, questions or gold.");
                }

                $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
                $id = (string) $row['id'];

                // meta.split is what the exporter wrote; recompute when it is absent
                // so a hand-written dataset still lands in a stable split.
                $caseSplit = is_string($meta['split'] ?? null) ? $meta['split'] : SplitAssigner::for($id);

                if ($split !== null && $caseSplit !== $split) {
                    continue;
                }

                yield new DatasetCase(
                    id: $id,
                    state: $row['state'],
                    questions: $row['questions'],
                    gold: $row['gold'],
                    meta: $meta,
                    split: $caseSplit,
                );
            }
        } finally {
            fclose($handle);
        }
    }
}
