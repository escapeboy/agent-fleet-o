<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\ParsedTranscript;
use App\Infrastructure\AI\DTOs\TranscriptTurn;

/**
 * Parses a Claude Code session transcript (line-delimited JSON, as written to
 * `~/.claude/projects/<project>/<session-id>.jsonl`) into normalized turns.
 *
 * Borrowed approach: passively read the runtime's own transcript file — no
 * instrumentation in the agent (see ClawMetry research note).
 *
 * Local-agent transcripts are unstable: lines can be truncated, duplicated, or
 * interleaved with non-message records (summaries, hooks). Parsing is therefore
 * defensive — any line that does not decode to a usable message object is
 * skipped rather than aborting the whole transcript.
 */
class ClaudeCodeTranscriptParser
{
    /**
     * Hard ceiling on parsed turns. Bounds the work an oversized or hostile
     * transcript can amplify into downstream span dispatches — the byte-length
     * cap on the tool input does not bound turn/tool count on its own.
     */
    public const MAX_TURNS = 2000;

    public function parse(string $jsonl): ParsedTranscript
    {
        $turns = [];
        $sessionId = null;
        $index = 0;
        $truncated = false;

        foreach (preg_split('/\r\n|\n|\r/', $jsonl) ?: [] as $line) {
            if ($index >= self::MAX_TURNS) {
                $truncated = true;
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $record = json_decode($line, true);
            if (! is_array($record)) {
                continue;
            }

            if ($sessionId === null && is_string($record['sessionId'] ?? null)) {
                $sessionId = $record['sessionId'];
            }

            $type = $record['type'] ?? null;
            if ($type !== 'user' && $type !== 'assistant') {
                continue;
            }

            $message = is_array($record['message'] ?? null) ? $record['message'] : [];
            $role = ($message['role'] ?? $type) === 'assistant' ? 'assistant' : 'user';

            $turns[] = new TranscriptTurn(
                index: $index++,
                role: $role,
                model: is_string($message['model'] ?? null) ? $message['model'] : null,
                promptTokens: $this->promptTokens($message['usage'] ?? null),
                completionTokens: $this->intValue($message['usage']['output_tokens'] ?? null),
                toolCalls: $this->toolCalls($message['content'] ?? null),
                text: $this->text($message['content'] ?? null),
                timestampNanos: $this->timestampNanos($record['timestamp'] ?? null),
            );
        }

        return new ParsedTranscript($sessionId, $turns, $truncated);
    }

    /**
     * Prompt-side tokens, including cache reads/creation so the span reflects
     * the full input footprint the way the cloud gateway's usage does.
     */
    private function promptTokens(mixed $usage): int
    {
        if (! is_array($usage)) {
            return 0;
        }

        return $this->intValue($usage['input_tokens'] ?? null)
            + $this->intValue($usage['cache_read_input_tokens'] ?? null)
            + $this->intValue($usage['cache_creation_input_tokens'] ?? null);
    }

    /**
     * @return list<array{name: string, input: array<string, mixed>}>
     */
    private function toolCalls(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        $calls = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_use' && is_string($block['name'] ?? null)) {
                $calls[] = [
                    'name' => $block['name'],
                    'input' => is_array($block['input'] ?? null) ? $block['input'] : [],
                ];
            }
        }

        return $calls;
    }

    private function text(mixed $content): ?string
    {
        if (is_string($content)) {
            return $content !== '' ? $content : null;
        }

        if (! is_array($content)) {
            return null;
        }

        $parts = [];
        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function timestampNanos(mixed $timestamp): int
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return 0;
        }

        try {
            $dt = new \DateTimeImmutable($timestamp);
        } catch (\Exception) {
            return 0;
        }

        return ((int) $dt->format('U')) * 1_000_000_000 + ((int) $dt->format('u')) * 1_000;
    }

    private function intValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
