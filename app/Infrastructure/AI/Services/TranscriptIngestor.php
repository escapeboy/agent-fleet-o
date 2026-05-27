<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\TranscriptTurn;
use App\Infrastructure\AI\Jobs\ExportToPhoenixJob;

/**
 * Replays a parsed local-agent transcript into Phoenix as a trace.
 *
 * Emits one session root span plus a child span per assistant turn (LLM) and
 * per tool call (TOOL), all sharing one traceId and parenting to the root span.
 * This mirrors the proven `PhoenixTraceContext` (root) + `PhoenixExportMiddleware`
 * (children reference the root span id) convention exactly, so the trace renders
 * the same way the cloud `agent.execute` tree does.
 *
 * Fire-and-forget: gated behind two config flags and never throws into the
 * caller — observability must not break the run it observes.
 */
class TranscriptIngestor
{
    /**
     * Hard ceiling on spans emitted per ingest call. The parser already caps
     * turns, but tool calls per turn are unbounded — this bounds total queue
     * dispatches so one call can't amplify into thousands of export jobs.
     */
    public const MAX_SPANS = 5000;

    public function __construct(private readonly ClaudeCodeTranscriptParser $parser) {}

    /**
     * @param  array{source?: string, agent_id?: ?string, experiment_id?: ?string, team_id?: ?string, trace_id?: ?string, mask?: bool}  $context
     * @return array<string, mixed>
     */
    public function ingest(string $transcript, array $context = []): array
    {
        if (! (bool) config('llmops.transcript_ingest.enabled', false)
            || ! (bool) config('llmops.phoenix.enabled', false)) {
            return ['ingested' => false, 'reason' => 'disabled'];
        }

        $parsed = $this->parser->parse($transcript);

        if ($parsed->turnCount() === 0) {
            return ['ingested' => false, 'reason' => 'empty'];
        }

        $source = is_string($context['source'] ?? null) ? $context['source'] : 'claude-code';
        $mask = array_key_exists('mask', $context)
            ? (bool) $context['mask']
            : (bool) config('llmops.phoenix.mask_content', false);

        $endpoint = (string) config('llmops.phoenix.endpoint', '');
        $apiKey = (string) config('llmops.phoenix.api_key', '');
        $project = (string) config('llmops.phoenix.project', 'fleetq');

        $traceId = (is_string($context['trace_id'] ?? null) && strlen($context['trace_id']) === 32)
            ? $context['trace_id']
            : $this->randomHex(32);
        $rootSpanId = $this->randomHex(16);

        $meta = [
            'metadata.source' => $source,
            'metadata.session_id' => $parsed->sessionId,
            'metadata.agent_id' => $context['agent_id'] ?? null,
            'metadata.experiment_id' => $context['experiment_id'] ?? null,
            'metadata.team_id' => $context['team_id'] ?? null,
        ];

        [$turnStart, $turnEnd] = $this->turnWindows($parsed->turns);
        $spans = 0;
        $truncated = $parsed->truncated;

        // Session root span (no parent — same shape as PhoenixTraceContext root).
        $this->dispatch($endpoint, 'local_agent.session', array_merge($meta, [
            OpenInferenceAttributes::SPAN_KIND => 'AGENT',
            'metadata.turn_count' => $parsed->turnCount(),
            'metadata.tool_calls_count' => $parsed->toolCallCount() ?: null,
            OpenInferenceAttributes::LLM_TOKEN_COUNT_PROMPT => $parsed->totalPromptTokens(),
            OpenInferenceAttributes::LLM_TOKEN_COUNT_COMPLETION => $parsed->totalCompletionTokens(),
            OpenInferenceAttributes::LLM_TOKEN_COUNT_TOTAL => $parsed->totalPromptTokens() + $parsed->totalCompletionTokens(),
        ]), $turnStart[0], max($turnEnd), $apiKey, $project, $traceId, $rootSpanId, null);
        $spans++;

        foreach ($parsed->turns as $i => $turn) {
            if ($turn->isAssistant()) {
                if ($spans >= self::MAX_SPANS) {
                    $truncated = true;
                    break;
                }
                $this->dispatch($endpoint, 'local_agent.turn', array_merge($meta, [
                    OpenInferenceAttributes::SPAN_KIND => 'LLM',
                    OpenInferenceAttributes::LLM_MODEL => $turn->model,
                    OpenInferenceAttributes::LLM_PROVIDER => $source,
                    OpenInferenceAttributes::LLM_TOKEN_COUNT_PROMPT => $turn->promptTokens,
                    OpenInferenceAttributes::LLM_TOKEN_COUNT_COMPLETION => $turn->completionTokens,
                    OpenInferenceAttributes::LLM_TOKEN_COUNT_TOTAL => $turn->promptTokens + $turn->completionTokens,
                    'llm.output_messages.0.message.role' => 'assistant',
                    'llm.output_messages.0.message.content' => $mask ? OpenInferenceAttributes::MASKED : $turn->text,
                    'metadata.turn_index' => $turn->index,
                    'metadata.tool_calls_count' => count($turn->toolCalls) ?: null,
                ]), $turnStart[$i], $turnEnd[$i], $apiKey, $project, $traceId, $this->randomHex(16), $rootSpanId);
                $spans++;
            }

            foreach ($turn->toolCalls as $call) {
                if ($spans >= self::MAX_SPANS) {
                    $truncated = true;
                    break 2;
                }
                $this->dispatch($endpoint, 'local_agent.tool.'.$call['name'], array_merge($meta, [
                    OpenInferenceAttributes::SPAN_KIND => 'TOOL',
                    'tool.name' => $call['name'],
                    'tool.parameters' => $mask ? OpenInferenceAttributes::MASKED : json_encode($call['input']),
                    'metadata.turn_index' => $turn->index,
                ]), $turnStart[$i], $turnEnd[$i], $apiKey, $project, $traceId, $this->randomHex(16), $rootSpanId);
                $spans++;
            }
        }

        return [
            'ingested' => true,
            'trace_id' => $traceId,
            'session_id' => $parsed->sessionId,
            'spans_emitted' => $spans,
            'turns' => $parsed->turnCount(),
            'tool_calls' => $parsed->toolCallCount(),
            'prompt_tokens' => $parsed->totalPromptTokens(),
            'completion_tokens' => $parsed->totalCompletionTokens(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Per-turn [start, end] nanosecond windows. A turn ends when the next turn
     * starts; turns with no parseable timestamp fall back to "now", and the
     * final turn gets a 1ms floor so the span has non-zero duration.
     *
     * @param  list<TranscriptTurn>  $turns
     * @return array{0: list<int>, 1: list<int>}
     */
    private function turnWindows(array $turns): array
    {
        $now = (int) (microtime(true) * 1_000_000_000);

        $starts = array_map(
            fn (TranscriptTurn $t): int => $t->timestampNanos > 0 ? $t->timestampNanos : $now,
            $turns,
        );

        $ends = [];
        $count = count($starts);
        foreach ($starts as $i => $start) {
            $next = $starts[$i + 1] ?? null;
            $ends[$i] = ($next !== null && $next > $start) ? $next : $start + 1_000_000;
        }

        return [$starts, $ends];
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function dispatch(
        string $endpoint,
        string $spanName,
        array $attributes,
        int $startNanos,
        int $endNanos,
        string $apiKey,
        string $project,
        string $traceId,
        string $spanId,
        ?string $parentSpanId,
    ): void {
        ExportToPhoenixJob::dispatch(
            endpoint: $endpoint,
            spanName: $spanName,
            attributes: array_filter($attributes, fn ($v): bool => $v !== null && $v !== ''),
            startNanos: $startNanos,
            endNanos: $endNanos,
            apiKey: $apiKey,
            project: $project,
            traceId: $traceId,
            spanId: $spanId,
            parentSpanId: $parentSpanId,
        );
    }

    private function randomHex(int $length): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }
}
