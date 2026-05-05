<?php

namespace App\Domain\Assistant\Actions;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Mcp\DeadlineContext;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool as PrismToolObject;
use Prism\Prism\ValueObjects\ToolOutput;

class LocalToolLoopExecutor
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Execute a tool calling loop for local agents that don't support PrismPHP tools.
     *
     * Flow:
     * 1. Send prompt with tool schemas to local agent
     * 2. Parse response for <tool_call> tags
     * 3. Execute matching tools
     * 4. Send another request with tool results appended
     * 5. Repeat until no tool calls or max steps reached
     *
     * @param  array<PrismToolObject>  $tools
     */
    public function execute(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $tools,
        User $user,
        ?callable $onChunk = null,
    ): AiResponseDTO {
        $toolMap = [];
        foreach ($tools as $tool) {
            $toolMap[$tool->name()] = $tool;
        }

        $maxSteps = 3;
        $allToolResults = [];
        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;
        $totalLatencyMs = 0;
        $currentPrompt = $userPrompt;
        $lastResponse = null;

        $deadlineContext = app(DeadlineContext::class);

        for ($step = 0; $step < $maxSteps; $step++) {
            $deadlineContext->assertNotExpired();

            $request = new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $currentPrompt,
                maxTokens: 4096,
                userId: $user->id,
                teamId: $user->current_team_id,
                purpose: 'platform_assistant',
                temperature: 0.3,
            );

            if ($onChunk !== null) {
                // Stream with <tool_call> blocks filtered from the visible output.
                // $stepAccumulated tracks the full raw text; $cleanAccumulated tracks
                // what is shown in the UI (no tool_call XML).
                $stepAccumulated = '';
                $cleanAccumulated = '';
                $response = $this->gateway->stream(
                    $request,
                    function (string $chunk) use ($onChunk, &$stepAccumulated, &$cleanAccumulated): void {
                        $stepAccumulated .= $chunk;
                        $clean = trim(preg_replace('/<tool_call>\s*\{.+?\}\s*<\/tool_call>/s', '', $stepAccumulated));
                        if ($clean !== $cleanAccumulated) {
                            $cleanAccumulated = $clean;
                            if ($clean !== '') {
                                $onChunk($clean);
                            }
                        }
                    },
                );
            } else {
                $response = $this->gateway->complete($request);
            }
            $lastResponse = $response;
            $totalPromptTokens += $response->usage->promptTokens;
            $totalCompletionTokens += $response->usage->completionTokens;
            $totalLatencyMs += $response->latencyMs;

            // Parse tool calls from the response text
            $toolCalls = $this->parseToolCalls($response->content);

            if (empty($toolCalls)) {
                break; // No tool calls — done
            }

            // Execute each tool and collect results
            $resultsText = '';
            foreach ($toolCalls as $call) {
                $toolName = $call['name'];
                $args = $call['arguments'];

                if (! isset($toolMap[$toolName])) {
                    $resultsText .= "<tool_result name=\"{$toolName}\">\n".json_encode(['error' => "Unknown tool: {$toolName}"])."\n</tool_result>\n\n";

                    continue;
                }

                try {
                    $result = $toolMap[$toolName]->handle(...$args);
                    $resultStr = $result instanceof ToolOutput ? $result->output : (string) $result;
                    $allToolResults[] = [
                        'toolName' => $toolName,
                        'args' => $args,
                        'result' => $resultStr,
                    ];
                    $resultsText .= "<tool_result name=\"{$toolName}\">\n{$resultStr}\n</tool_result>\n\n";

                    Log::debug("Assistant local tool executed: {$toolName}", ['args' => $args]);
                } catch (\Throwable $e) {
                    $errorResult = json_encode(['error' => $e->getMessage()]);
                    $resultsText .= "<tool_result name=\"{$toolName}\">\n{$errorResult}\n</tool_result>\n\n";

                    Log::warning("Assistant local tool failed: {$toolName}", ['error' => $e->getMessage()]);
                }
            }

            // Build next prompt: original + assistant's response (without tool_call tags) + tool results
            $cleanedContent = $this->stripToolCalls($response->content);
            $currentPrompt = $userPrompt
                ."\n\n[Assistant's previous response]:\n".$cleanedContent
                ."\n\n[Tool results]:\n".$resultsText
                ."\nNow provide your final response to the user, incorporating the tool results above. Do not call tools again unless absolutely necessary.";
        }

        // Build final response with accumulated usage
        $finalContent = $lastResponse ? $this->stripToolCalls($lastResponse->content) : '';

        // If response is empty after sanitization (e.g. raw events only), provide fallback
        if ($finalContent === '' && ! empty($allToolResults)) {
            $finalContent = 'Tool operations completed. Results: '.json_encode(
                array_map(fn ($r) => ['tool' => $r['toolName'], 'result' => json_decode($r['result'], true)], $allToolResults),
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
            );
        } elseif ($finalContent === '') {
            $finalContent = 'Sorry, the local agent did not produce a valid response. Please try again or switch to a cloud provider.';
        }

        return new AiResponseDTO(
            content: $finalContent,
            parsedOutput: $lastResponse?->parsedOutput,
            usage: new AiUsageDTO(
                promptTokens: $totalPromptTokens,
                completionTokens: $totalCompletionTokens,
                costCredits: 0, // Local agents are free
            ),
            provider: $provider,
            model: $model,
            latencyMs: $totalLatencyMs,
            toolResults: ! empty($allToolResults) ? $allToolResults : null,
            toolCallsCount: count($allToolResults),
            stepsCount: $step + 1,
        );
    }

    /**
     * Parse <tool_call> tags from a local agent's text response.
     *
     * Expected format:
     * <tool_call>
     * {"name": "tool_name", "arguments": {"param": "value"}}
     * </tool_call>
     *
     * @return array<array{name: string, arguments: array}>
     */
    private function parseToolCalls(string $content): array
    {
        $calls = [];

        if (preg_match_all('/<tool_call>\s*(\{.+?\})\s*<\/tool_call>/s', $content, $matches)) {
            foreach ($matches[1] as $json) {
                $parsed = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($parsed['name'])) {
                    $calls[] = [
                        'name' => $parsed['name'],
                        'arguments' => $parsed['arguments'] ?? [],
                    ];
                }
            }
        }

        return $calls;
    }

    /**
     * Remove <tool_call> blocks from content, leaving only the natural language parts.
     */
    private function stripToolCalls(string $content): string
    {
        $cleaned = trim(preg_replace('/<tool_call>\s*\{.+?\}\s*<\/tool_call>/s', '', $content));

        return $this->sanitizeLocalResponse($cleaned);
    }

    /**
     * Detect and clean raw JSONL/streaming events that leak from local agent output.
     *
     * Local agents (codex, claude-code) in --json mode output JSONL events.
     * If parseOutput fails to extract clean content, raw events may leak into
     * the response. This method detects and strips them.
     */
    private function sanitizeLocalResponse(string $content): string
    {
        if ($content === '') {
            return $content;
        }

        // Detect if the entire content is a raw JSON event (e.g. {"type": "turn.started"})
        $json = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json) && isset($json['type'])) {
            $eventType = $json['type'];

            // Known streaming event types that are NOT content
            $streamingEvents = ['turn.started', 'turn.completed', 'thread.started', 'item.started', 'item.completed', 'content_block_delta', 'stream_event'];
            if (in_array($eventType, $streamingEvents)) {
                // Try to extract content from item.completed agent_message
                if ($eventType === 'item.completed' && ($json['item']['type'] ?? '') === 'agent_message') {
                    return $json['item']['text'] ?? '';
                }

                Log::warning('Assistant: raw streaming event leaked as response', ['type' => $eventType]);

                return '';
            }
        }

        return $content;
    }
}
