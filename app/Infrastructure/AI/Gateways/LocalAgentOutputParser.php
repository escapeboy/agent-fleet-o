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

    /**
     * Rough token estimate (4 chars per token).
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
}
