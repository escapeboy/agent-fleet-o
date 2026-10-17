<?php

namespace App\Infrastructure\AI\Gateways;

final class LocalAgentOutputParser
{
    /**
     * Parse output from the local agent CLI.
     *
     * @return array{content: string, structured: array|null}
     */
    public static function parseOutput(string $agentKey, string $rawOutput): array
    {
        $rawOutput = trim($rawOutput);

        if (empty($rawOutput)) {
            return ['content' => '', 'structured' => null];
        }

        // Try JSON parse first (single JSON object)
        $json = json_decode($rawOutput, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return self::extractFromJson($agentKey, $json);
        }

        // JSONL: parse all lines and extract content from the event stream.
        // Supports both Codex (`exec --json`) and Claude Code (`--output-format stream-json`).
        $lines = explode("\n", $rawOutput);
        $agentMessages = [];
        $allEvents = [];
        $usageEvent = null;
        $resultEvent = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            $allEvents[] = $decoded;
            $eventType = $decoded['type'] ?? '';

            // Codex: collect agent_message text from item.completed events
            if ($eventType === 'item.completed'
                && ($decoded['item']['type'] ?? '') === 'agent_message'
                && isset($decoded['item']['text'])) {
                $agentMessages[] = $decoded['item']['text'];
            }

            // Claude Code stream-json: extract text from assistant messages
            if ($eventType === 'assistant' && isset($decoded['message']['content'])) {
                foreach ($decoded['message']['content'] as $block) {
                    if (($block['type'] ?? '') === 'text' && ! empty($block['text'])) {
                        $agentMessages[] = $block['text'];
                    }
                }
            }

            // Claude Code stream-json: result event with final content
            if ($eventType === 'result' && isset($decoded['result'])) {
                $resultEvent = $decoded;
            }

            // Codex: capture usage from turn.completed
            if ($eventType === 'turn.completed') {
                $usageEvent = $decoded;
            }
        }

        // If we found a result event (Claude Code stream-json), prefer it
        if ($resultEvent) {
            $resultContent = is_string($resultEvent['result'])
                ? $resultEvent['result']
                : json_encode($resultEvent['result']);

            return [
                'content' => $resultContent,
                'structured' => $resultEvent,
            ];
        }

        // If we found agent messages in the event stream, use them
        if (! empty($agentMessages)) {
            $content = implode("\n\n", $agentMessages);

            return [
                'content' => $content,
                'structured' => [
                    'type' => 'result',
                    'result' => $content,
                    'usage' => $usageEvent['usage'] ?? null,
                ],
            ];
        }

        // Fallback: use the last valid JSON event (for Claude Code or other formats)
        $lastEvent = end($allEvents) ?: null;

        if ($lastEvent) {
            return self::extractFromJson($agentKey, $lastEvent);
        }

        // Raw text fallback
        return ['content' => $rawOutput, 'structured' => null];
    }

    /**
     * Extract content from a parsed JSON result.
     *
     * @return array{content: string, structured: array|null}
     */
    public static function extractFromJson(string $agentKey, array $json): array
    {
        // Claude Code JSON output: { "result": "...", "cost_usd": 0.01, ... }
        // Or it may be an array of message objects
        if (isset($json['result'])) {
            return [
                'content' => is_string($json['result']) ? $json['result'] : json_encode($json['result']),
                'structured' => $json,
            ];
        }

        // Gemini CLI JSON output: { "response": { "text": "..." } } or { "text": "..." }
        if (isset($json['response']['text'])) {
            return [
                'content' => $json['response']['text'],
                'structured' => $json,
            ];
        }
        if (isset($json['text']) && is_string($json['text'])) {
            return [
                'content' => $json['text'],
                'structured' => $json,
            ];
        }

        // Amp --stream-json: { "type": "assistant", "content": "..." }
        // or final message with content field
        if (isset($json['content'])) {
            return [
                'content' => is_string($json['content']) ? $json['content'] : json_encode($json['content']),
                'structured' => $json,
            ];
        }

        // OpenCode JSON output: { "output": "..." } or { "message": "..." }
        if (isset($json['output']) && is_string($json['output'])) {
            return [
                'content' => $json['output'],
                'structured' => $json,
            ];
        }
        if (isset($json['message']) && is_string($json['message'])) {
            return [
                'content' => $json['message'],
                'structured' => $json,
            ];
        }

        // If it's an array of messages, get the last assistant message
        if (isset($json[0]) && is_array($json[0])) {
            $assistantMessages = array_filter($json, fn ($m) => ($m['role'] ?? '') === 'assistant');
            $last = end($assistantMessages);

            if ($last && isset($last['content'])) {
                $content = is_array($last['content'])
                    ? collect($last['content'])->where('type', 'text')->pluck('text')->implode("\n")
                    : $last['content'];

                return ['content' => $content, 'structured' => $json];
            }
        }

        // Generic fallback: stringify the whole thing
        return [
            'content' => json_encode($json, JSON_PRETTY_PRINT),
            'structured' => $json,
        ];
    }

    /**
     * Extract human-readable text from a Claude Code stream-json or Codex JSONL event.
     *
     * Returns the text content if the event contains displayable output, null otherwise.
     */
    public static function extractTextFromStreamEvent(string $eventLine): ?string
    {
        $event = json_decode($eventLine, true);

        if (! is_array($event)) {
            return null;
        }

        $type = $event['type'] ?? '';

        // Claude Code 2.1+ wraps streaming events in a stream_event envelope.
        // Unwrap to get the inner event for content_block_delta extraction.
        if ($type === 'stream_event' && isset($event['event'])) {
            $inner = $event['event'];
            $innerType = $inner['type'] ?? '';

            if ($innerType === 'content_block_delta') {
                $delta = $inner['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta' && ! empty($delta['text'])) {
                    return $delta['text'];
                }
            }

            return null;
        }

        // Claude Code stream-json: assistant message with text content blocks
        if ($type === 'assistant') {
            $content = $event['message']['content'] ?? [];
            $texts = [];

            foreach ($content as $block) {
                if (($block['type'] ?? '') === 'text' && ! empty($block['text'])) {
                    $texts[] = $block['text'];
                }
            }

            return ! empty($texts) ? implode("\n", $texts) : null;
        }

        // Claude Code stream-json (pre-2.1): content_block_delta with incremental text
        if ($type === 'content_block_delta') {
            $delta = $event['delta'] ?? [];
            if (($delta['type'] ?? '') === 'text_delta' && ! empty($delta['text'])) {
                return $delta['text'];
            }
        }

        // Codex: agent_message text from item.completed events
        if ($type === 'item.completed'
            && ($event['item']['type'] ?? '') === 'agent_message'
            && isset($event['item']['text'])) {
            return $event['item']['text'];
        }

        // Amp --stream-json: text content events
        if ($type === 'text' && ! empty($event['content'])) {
            return $event['content'];
        }

        // Gemini CLI stream-json: incremental text deltas
        if ($type === 'text_delta' && ! empty($event['text'])) {
            return $event['text'];
        }

        // Result events are handled by the done event — don't broadcast them as chunks
        return null;
    }

    public const MAX_BUILTIN_TOOL_CALLS = 500;

    /**
     * Names of the built-in (non-`mcp__`) tools the Claude Code CLI called, read
     * from the `tool_use` blocks of `assistant` events in a stream-json
     * transcript, in transcript order, one entry per call. Malformed lines are
     * skipped.
     *
     * For each `Bash` call it also returns the program name only (see
     * bashProgram()); arguments and the rest of the command are never kept.
     * Both lists are capped at MAX_BUILTIN_TOOL_CALLS independently.
     *
     * @return array{names: list<string>, truncated: bool, bash_programs: list<string>, bash_truncated: bool}
     */
    public static function builtinToolCalls(string $streamJson): array
    {
        $names = [];
        $programs = [];
        $truncated = false;
        $bashTruncated = false;

        foreach (preg_split('/\r\n|\n|\r/', $streamJson) ?: [] as $line) {
            $event = json_decode(trim($line), true);
            if (! is_array($event) || ($event['type'] ?? null) !== 'assistant') {
                continue;
            }
            $content = $event['message']['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (! is_array($block) || ($block['type'] ?? null) !== 'tool_use') {
                    continue;
                }
                $name = $block['name'] ?? null;
                if (! is_string($name) || $name === '' || str_starts_with($name, 'mcp__')) {
                    continue;
                }
                if (count($names) >= self::MAX_BUILTIN_TOOL_CALLS) {
                    $truncated = true;
                } else {
                    $names[] = $name;
                }
                if ($name === 'Bash') {
                    if (count($programs) >= self::MAX_BUILTIN_TOOL_CALLS) {
                        $bashTruncated = true;
                    } else {
                        $input = $block['input'] ?? null;
                        $command = is_array($input) ? ($input['command'] ?? null) : null;
                        $programs[] = self::bashProgram($command);
                    }
                }
            }
        }

        return [
            'names' => $names,
            'truncated' => $truncated,
            'bash_programs' => $programs,
            'bash_truncated' => $bashTruncated,
        ];
    }

    /**
     * The program a Bash command runs: its first command word, after leading
     * `VAR=value` assignments and sudo/env/time/nohup, reduced to a basename of
     * at most 40 chars of [A-Za-z0-9._+-]. `?` when empty or unparseable.
     * Nothing but that one word is ever returned — arguments may hold secrets.
     */
    public static function bashProgram(mixed $command): string
    {
        if (! is_string($command)) {
            return '?';
        }

        $pos = 0;
        $len = strlen($command);

        while (true) {
            $word = self::nextShellWord($command, $pos, $len);
            if ($word === null) {
                return '?';
            }
            [$raw, $text] = $word;
            if ($raw === '') {
                return '?';
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $raw) === 1) {
                continue;
            }
            if (in_array($text, ['sudo', 'env', 'time', 'nohup'], true)) {
                continue;
            }
            $base = ($slash = strrpos($text, '/')) === false ? $text : substr($text, $slash + 1);
            $clean = substr((string) preg_replace('/[^A-Za-z0-9._+-]/', '', $base), 0, 40);

            return $clean === '' ? '?' : $clean;
        }
    }

    /**
     * Reads one shell word starting at $pos (advanced past it). Returns
     * [raw, unquoted] or null on an unterminated quote. A word ends at
     * whitespace or ; | & < > ( ) outside quotes; an empty raw word means the
     * command starts with such an operator or is exhausted.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function nextShellWord(string $s, int &$pos, int $len): ?array
    {
        while ($pos < $len && ctype_space($s[$pos])) {
            $pos++;
        }

        $raw = '';
        $text = '';
        $quote = null;

        while ($pos < $len) {
            $c = $s[$pos];
            if ($quote !== null) {
                $raw .= $c;
                $pos++;
                if ($c === $quote) {
                    $quote = null;
                } else {
                    $text .= $c;
                }

                continue;
            }
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $raw .= $c;
                $pos++;

                continue;
            }
            if (ctype_space($c) || str_contains(';|&<>()', $c)) {
                break;
            }
            $raw .= $c;
            $text .= $c;
            $pos++;
        }

        return $quote !== null ? null : [$raw, $text];
    }

    /**
     * Rough token estimate (4 chars per token).
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
}
