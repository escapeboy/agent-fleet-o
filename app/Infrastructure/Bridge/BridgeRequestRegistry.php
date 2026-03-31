<?php

namespace App\Infrastructure\Bridge;

use Illuminate\Support\Facades\Redis;

/**
 * Manages in-flight bridge relay requests in Redis.
 *
 * Key schema:
 *   bridge:pending:{request_id}  — String, TTL 90s  — marks request as in-flight
 *   bridge:stream:{request_id}   — List,   TTL 120s — chunk stream (RPUSH / BLPOP)
 *   bridge:usage:{request_id}    — String, TTL 120s — final token usage from daemon
 */
class BridgeRequestRegistry
{
    private const PENDING_TTL = 600;

    private const STREAM_TTL = 600;

    public function register(string $requestId, string $teamId): void
    {
        Redis::connection('bridge')->setex(
            "bridge:pending:{$requestId}",
            self::PENDING_TTL,
            $teamId,
        );
    }

    /**
     * Push a chunk onto the stream list. A JSON-encoded sentinel is pushed on done.
     */
    public function pushChunk(string $requestId, string $chunk, bool $done, ?array $usage = null): void
    {
        $payload = json_encode(['chunk' => $chunk, 'done' => $done, 'usage' => $usage]);

        $conn = Redis::connection('bridge');
        $conn->rpush("bridge:stream:{$requestId}", $payload);
        $conn->expire("bridge:stream:{$requestId}", self::STREAM_TTL);
    }

    /**
     * Store the final usage counts after the stream is complete.
     */
    public function storeUsage(string $requestId, array $usage): void
    {
        Redis::connection('bridge')->setex(
            "bridge:usage:{$requestId}",
            self::STREAM_TTL,
            json_encode($usage),
        );
    }

    /**
     * Blocking pop — waits up to $timeoutSeconds for the next chunk.
     * Returns null on timeout.
     *
     * The relay binary writes responseEnvelope{frame_type uint16, payload []byte, done bool}.
     * Go's json.Marshal encodes []byte as base64, so we must base64-decode the payload field
     * before JSON-decoding the actual frame contents (LLMResponseChunk, AgentEvent, etc.).
     *
     * Frame type constants (from tunnel/framing.go):
     *   0x0002 FrameLLMResponseChunk  — streaming delta, payload = LLMResponseChunk{delta, done}
     *   0x0003 FrameLLMResponseEnd    — final frame, payload = (empty or usage JSON)
     *   0x0011 FrameAgentEvent        — agent output, payload = AgentEvent{kind, text, error}
     *   0x0012 FrameAgentDone         — agent done
     *   0x0020 FrameMcpToolCall       — MCP tool call (outbound, not seen in responses)
     *   0x0021 FrameMcpToolResult     — MCP tool result, payload = raw MCP JSON-RPC result
     *   0x00FF FrameError             — bridge error, payload = ErrorPayload{code, message}
     *
     * @return array{chunk: string, done: bool, usage: array|null}|null
     */
    public function popChunk(string $requestId, int $timeoutSeconds = 90): ?array
    {
        $result = Redis::connection('bridge')->blpop(["bridge:stream:{$requestId}"], $timeoutSeconds);

        if (! $result) {
            return null;
        }

        // blpop returns [key, value]
        $envelope = json_decode($result[1], true);
        if (! is_array($envelope)) {
            return null;
        }

        // Reverb path: HandleBridgeRelayResponse pushes {chunk, done, usage} directly.
        // Detect by presence of 'chunk' key and absence of 'frame_type'.
        if (array_key_exists('chunk', $envelope) && ! isset($envelope['frame_type'])) {
            return [
                'chunk' => $envelope['chunk'] ?? '',
                'done' => (bool) ($envelope['done'] ?? false),
                'usage' => $envelope['usage'] ?? null,
            ];
        }

        // Relay binary path: frame-based protocol with base64-encoded payload.
        $frameType = (int) ($envelope['frame_type'] ?? 0);
        $done = (bool) ($envelope['done'] ?? false);

        // Go's json.Marshal encodes []byte as standard base64 string
        $rawPayload = $envelope['payload'] ?? '';
        $payloadBytes = $rawPayload !== '' ? base64_decode($rawPayload, strict: true) : '';
        $payload = ($payloadBytes !== false && $payloadBytes !== '')
            ? json_decode($payloadBytes, true)
            : [];

        // FrameError = 0x00FF — store error so getUsage() returns the sentinel
        if ($frameType === 0x00FF) {
            $this->storeUsage($requestId, ['__error' => $payload['message'] ?? 'Bridge error']);

            return ['chunk' => '', 'done' => true, 'usage' => null];
        }

        // FrameAgentEvent = 0x0011 / FrameAgentDone = 0x0012
        if ($frameType === 0x0011 || $frameType === 0x0012) {
            $error = $payload['error'] ?? '';
            if ($error !== '') {
                $this->storeUsage($requestId, ['__error' => $error]);

                return ['chunk' => '', 'done' => true, 'usage' => null];
            }

            // Only output and result carry meaningful text; progress is keepalive-only.
            $kind = $payload['kind'] ?? 'output';
            if ($kind === 'progress') {
                return ['chunk' => '', 'done' => $done, 'usage' => null];
            }

            return ['chunk' => $this->stripAnsi($payload['text'] ?? ''), 'done' => $done, 'usage' => null];
        }

        // FrameMcpToolResult = 0x0021 — MCP result relayed from bridge daemon.
        // The relay base64-encodes the daemon's {request_id, chunk, done} event.
        // The "chunk" field contains the raw MCP JSON-RPC result string.
        if ($frameType === 0x0021) {
            $chunk = $payload['chunk'] ?? (is_array($payload) ? json_encode($payload) : '');

            return ['chunk' => $chunk, 'done' => true, 'usage' => null];
        }

        // FrameLLMResponseChunk = 0x0002 — LLMResponseChunk{request_id, delta, done}
        // FrameLLMResponseEnd   = 0x0003 — final frame
        return ['chunk' => $payload['delta'] ?? '', 'done' => $done, 'usage' => null];
    }

    /**
     * Read stored usage (if available). Returns null if not present.
     *
     * @return array{prompt_tokens: int, completion_tokens: int}|null
     */
    public function getUsage(string $requestId): ?array
    {
        $raw = Redis::connection('bridge')->get("bridge:usage:{$requestId}");

        return $raw ? json_decode($raw, true) : null;
    }

    public function isExpired(string $requestId): bool
    {
        return Redis::connection('bridge')->ttl("bridge:pending:{$requestId}") <= 0;
    }

    /**
     * Strip ANSI escape sequences (colours, cursor movement, etc.) from agent output.
     * Many CLI agents (kiro, claude-code) emit coloured terminal output.
     */
    private function stripAnsi(string $text): string
    {
        // Matches ESC[ sequences (CSI), ESC] sequences (OSC), and bare ESC codes
        return (string) preg_replace('/\x1B(?:\[[0-9;?]*[ -\/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[@-_])/u', '', $text);
    }
}
