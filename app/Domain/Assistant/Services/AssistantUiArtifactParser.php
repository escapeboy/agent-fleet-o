<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Assistant\Artifacts\ArtifactFactory;
use App\Domain\Assistant\Artifacts\BaseArtifact;
use Illuminate\Support\Facades\Log;

/**
 * Extracts UI artifacts from an assistant reply that follows this convention:
 *
 *     <normal text body>
 *
 *     <<<FLEETQ_ARTIFACTS>>>
 *     {"artifacts": [ {"type": "data_table", ...}, ... ]}
 *     <<<END>>>
 *
 * The delimiter block is stripped from the visible text. The artifacts are
 * run through the sanitizing ArtifactFactory before returning. Anything
 * malformed silently degrades to an empty artifact list + full text body.
 *
 * Chosen over a wrapping JSON envelope because:
 *  - Works mid-stream (the text is visible as soon as it arrives; the block
 *    comes at the end).
 *  - Backward compatible: old replies without the delimiter just pass through.
 *  - Easy to parse deterministically (no LLM-json-mode quirks).
 *  - Failure-resistant: if JSON is broken, we still show the text.
 */
final class AssistantUiArtifactParser
{
    public const START_DELIMITER = '<<<FLEETQ_ARTIFACTS>>>';

    public const END_DELIMITER = '<<<END>>>';

    /**
     * @param  list<array<string, mixed>>  $toolCallsInTurn
     * @return array{text: string, artifacts: list<BaseArtifact>}
     */
    public function parse(string $rawContent, array $toolCallsInTurn): array
    {
        $startPos = strpos($rawContent, self::START_DELIMITER);

        if ($startPos === false) {
            return ['text' => $rawContent, 'artifacts' => []];
        }

        $afterStart = $startPos + strlen(self::START_DELIMITER);
        $endPos = strpos($rawContent, self::END_DELIMITER, $afterStart);

        // Missing END — strip everything from the start delimiter so we don't
        // leak half-written JSON to the user, then return empty artifacts.
        if ($endPos === false) {
            Log::info('AssistantUiArtifactParser: start delimiter without end, stripping tail');

            return [
                'text' => rtrim(substr($rawContent, 0, $startPos)),
                'artifacts' => [],
            ];
        }

        $text = rtrim(substr($rawContent, 0, $startPos));
        $jsonRaw = trim(substr($rawContent, $afterStart, $endPos - $afterStart));

        $decoded = json_decode($jsonRaw, true);
        if (! is_array($decoded)) {
            Log::info('AssistantUiArtifactParser: artifact block JSON parse failed', [
                'length' => strlen($jsonRaw),
            ]);

            return ['text' => $text, 'artifacts' => []];
        }

        $rawArtifacts = $decoded['artifacts'] ?? [];
        if (! is_array($rawArtifacts)) {
            return ['text' => $text, 'artifacts' => []];
        }

        $artifacts = ArtifactFactory::buildMany($rawArtifacts, $toolCallsInTurn);

        return ['text' => $text, 'artifacts' => $artifacts];
    }
}
