<?php

namespace App\Domain\Decision\Services;

use App\Domain\Decision\DTOs\Answer;
use App\Domain\Decision\DTOs\ChoiceAnswer;
use App\Domain\Decision\DTOs\NoulAnswer;
use App\Domain\Decision\DTOs\ScoreAnswer;

/**
 * The prompt and the answer mapping the LLM-backed drivers share.
 *
 * Shared on purpose: the gateway driver and the `claude -p` driver are only
 * comparable as baselines if the model is asked the same thing in the same
 * words. Any drift between them would show up as a model difference.
 */
final class StructuredDecisionPrompt
{
    /**
     * @param  array<string, array<string, mixed>>  $questions
     */
    public static function system(array $questions): string
    {
        $spec = [];

        foreach ($questions as $id => $question) {
            $spec[] = [
                'id' => (string) $id,
                'type' => $question['type'] ?? 'choice',
                'instructions' => $question['instructions'] ?? '',
                'criteria' => $question['criteria'] ?? null,
            ];
        }

        $json = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
        You answer typed questions about a state. You do not explain, you do not add prose.

        Answer every question independently. Judge each one only against the state the
        user supplies — never against another question's answer.

        Question types:
        - choice: pick exactly one key from that question's criteria object.
        - score: return the index of the matching level in that question's criteria array, 0-based.
        - noul: return how likely the statement is to be true, from 0.0 to 1.0.

        For choice and score, also return `probabilities`: one entry per available
        option, using the option key (choice) or the level index as a string (score).
        They must sum to 1.0 and reflect how likely each option is, not how much you
        like it. If you are genuinely torn between two options, say so in the numbers.

        Reply with one JSON object and nothing else:
        {"answers": [{"id": "<question id>", "value": "<answer>", "probabilities": [{"option": "<key>", "probability": 0.0}]}]}

        Questions:
        {$json}
        PROMPT;
    }

    /**
     * @param  array<string, mixed>|list<mixed>|string  $state
     */
    public static function user(array|string $state): string
    {
        return is_string($state)
            ? $state
            : (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Pull the first balanced JSON object out of a model's reply.
     *
     * Local agents wrap their output in markdown fences and sometimes emit the
     * same object twice, so `json_decode` on the whole string fails on answers
     * that are perfectly good. Scanning braces, and skipping over string
     * literals so a brace inside a value cannot end the scan early, is what
     * ProcessDocumentOcrAction already does for the same reason.
     */
    public static function extractFirstJsonObject(string $text): ?array
    {
        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $decoded = json_decode(substr($text, $start, $i - $start + 1), true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, array<string, mixed>>  $questions
     * @return array<string, Answer>
     */
    public static function answers(array $parsed, array $questions): array
    {
        $byId = [];

        foreach ($parsed['answers'] ?? [] as $entry) {
            if (is_array($entry) && isset($entry['id'])) {
                $byId[(string) $entry['id']] = $entry;
            }
        }

        $answers = [];

        foreach ($questions as $questionId => $question) {
            $entry = $byId[(string) $questionId] ?? null;

            if ($entry === null) {
                continue;
            }

            $answers[(string) $questionId] = self::mapAnswer(
                (string) ($question['type'] ?? 'choice'),
                $question,
                $entry,
            );
        }

        return $answers;
    }

    /**
     * @param  array<string, mixed>  $question
     * @param  array<string, mixed>  $entry
     */
    private static function mapAnswer(string $type, array $question, array $entry): Answer
    {
        $value = (string) ($entry['value'] ?? '');
        $probabilities = self::normalizeProbabilities($entry['probabilities'] ?? null);
        $confidence = $probabilities === null ? null : max($probabilities);

        return match ($type) {
            'noul' => new NoulAnswer(noul: (float) $value),
            'score' => new ScoreAnswer(
                score: (float) $value,
                probabilities: $probabilities,
                confidence: $confidence,
                legend: self::legend($question),
            ),
            default => new ChoiceAnswer(
                choice: $value,
                probabilities: $probabilities,
                confidence: $confidence,
            ),
        };
    }

    /**
     * @return array<string, float>|null
     */
    private static function normalizeProbabilities(mixed $raw): ?array
    {
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $map = [];

        foreach ($raw as $key => $item) {
            if (is_array($item) && isset($item['option'])) {
                $map[(string) $item['option']] = (float) ($item['probability'] ?? 0);
            } elseif (is_numeric($item)) {
                // Some replies give {"option": 0.7} instead of the array form.
                $map[(string) $key] = (float) $item;
            }
        }

        $sum = array_sum($map);

        if ($map === [] || $sum <= 0) {
            return null;
        }

        return array_map(static fn (float $p): float => $p / $sum, $map);
    }

    /**
     * @param  array<string, mixed>  $question
     * @return array<string, string>|null
     */
    private static function legend(array $question): ?array
    {
        $criteria = $question['criteria'] ?? null;

        if (! is_array($criteria) || ! array_is_list($criteria)) {
            return null;
        }

        $legend = [];

        foreach ($criteria as $index => $level) {
            $legend[(string) $index] = is_string($level) ? $level : (string) json_encode($level);
        }

        return $legend;
    }
}
