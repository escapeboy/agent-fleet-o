<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class ContextCompactor
{
    /** Stages reached during compaction, for metrics. */
    public const STAGE_NONE = 'none';

    public const STAGE_TOOL_OUTPUT = 'tool_output_compaction';

    public const STAGE_SUMMARIZATION = 'summarization';

    public const STAGE_SLIDING_WINDOW = 'sliding_window';

    public const STAGE_TRUNCATION = 'emergency_truncation';

    public function __construct(
        private readonly TokenEstimator $tokenEstimator,
    ) {}

    /**
     * Compact the user prompt to fit within the target token budget.
     *
     * Returns [compactedUserPrompt, stageReached].
     *
     * @return array{string, string}
     */
    public function compact(
        string $systemPrompt,
        string $userPrompt,
        int $targetTokens,
        float $utilization,
        string $summarizationModel,
        int $summarizationMaxTokens,
        int $minPreservedLines,
    ): array {
        $stageReached = self::STAGE_NONE;

        // Stage 1: Tool output compaction (collapse verbose tool results)
        $userPrompt = $this->compactToolOutputs($userPrompt);
        $stageReached = self::STAGE_TOOL_OUTPUT;

        if ($this->isWithinBudget($systemPrompt, $userPrompt, $targetTokens)) {
            return [$userPrompt, $stageReached];
        }

        // Stage 2: LLM-based summarization of older conversation history
        $thresholds = config('context_compaction', []);
        $windowThreshold = $thresholds['window_threshold'] ?? 0.85;

        if ($utilization >= ($thresholds['summarize_threshold'] ?? 0.70)) {
            $userPrompt = $this->summarizeOlderHistory($userPrompt, $summarizationModel, $summarizationMaxTokens, $minPreservedLines);
            $stageReached = self::STAGE_SUMMARIZATION;

            if ($this->isWithinBudget($systemPrompt, $userPrompt, $targetTokens)) {
                return [$userPrompt, $stageReached];
            }
        }

        // Stage 3: Sliding window — keep only recent lines
        if ($utilization >= $windowThreshold) {
            $userPrompt = $this->applySlidingWindow($userPrompt, $minPreservedLines);
            $stageReached = self::STAGE_SLIDING_WINDOW;

            if ($this->isWithinBudget($systemPrompt, $userPrompt, $targetTokens)) {
                return [$userPrompt, $stageReached];
            }
        }

        // Stage 4: Emergency truncation — hard cut to fit
        $emergencyThreshold = $thresholds['emergency_threshold'] ?? 0.92;
        if ($utilization >= $emergencyThreshold) {
            $userPrompt = $this->truncateToFit($systemPrompt, $userPrompt, $targetTokens);
            $stageReached = self::STAGE_TRUNCATION;

            Log::warning('ContextCompactor: emergency truncation reached', [
                'estimated_tokens_after' => $this->tokenEstimator->estimateRequest($systemPrompt, $userPrompt),
                'target_tokens' => $targetTokens,
            ]);
        }

        return [$userPrompt, $stageReached];
    }

    /**
     * Stage 1: Collapse verbose tool output blocks in the conversation.
     *
     * Tool outputs in the user prompt typically appear as:
     *   Tool result: <tool_name>
     *   <large block of output>
     *
     * We keep the last 2 tool result blocks intact and collapse older ones.
     */
    private function compactToolOutputs(string $userPrompt): string
    {
        // Match blocks that look like tool results (common patterns from PrismPHP/Assistant)
        // Pattern: lines containing "Tool result:" or JSON blocks after tool calls
        $pattern = '/(?:(?:Tool result|tool_result|<tool_result>).*?\n)([\s\S]*?)(?=\n(?:User:|Assistant:|Human:|Tool result|tool_result|<tool_result>|\z))/i';

        $matches = [];
        if (preg_match_all($pattern, $userPrompt, $matches, PREG_OFFSET_CAPTURE) === false || count($matches[0]) <= 2) {
            return $userPrompt;
        }

        // Keep the last 2 tool result blocks intact, collapse older ones
        $allMatches = $matches[0];
        $toCollapse = array_slice($allMatches, 0, -2);

        // Work backwards to preserve string offsets
        foreach (array_reverse($toCollapse) as $match) {
            $fullMatch = $match[0];
            $offset = $match[1];

            // Extract tool name if possible
            if (preg_match('/(?:Tool result|tool_result)[:\s]*(\w+)/i', $fullMatch, $nameMatch)) {
                $toolName = $nameMatch[1];
                $replacement = "Tool result: {$toolName}\n[output compacted — ".$this->tokenEstimator->estimate($fullMatch)." tokens saved]\n";
            } else {
                $replacement = '[tool output compacted — '.$this->tokenEstimator->estimate($fullMatch)." tokens saved]\n";
            }

            $userPrompt = substr_replace($userPrompt, $replacement, $offset, strlen($fullMatch));
        }

        return $userPrompt;
    }

    /**
     * Stage 2: Summarize older conversation history using a cheap LLM.
     */
    private function summarizeOlderHistory(
        string $userPrompt,
        string $summarizationModel,
        int $maxTokens,
        int $minPreservedLines,
    ): string {
        $lines = explode("\n", $userPrompt);

        // Ensure we have enough history to warrant summarization
        if (count($lines) <= $minPreservedLines * 3) {
            return $userPrompt;
        }

        // Split: keep the last $minPreservedLines * 3 lines as "recent"
        // (multiply by 3 to approximate preserving N conversation turns with multi-line messages)
        $preserveCount = min($minPreservedLines * 3, (int) floor(count($lines) * 0.4));
        $olderLines = array_slice($lines, 0, count($lines) - $preserveCount);
        $recentLines = array_slice($lines, count($lines) - $preserveCount);

        $olderText = implode("\n", $olderLines);

        if (mb_strlen($olderText) < 200) {
            return $userPrompt;
        }

        try {
            [$provider, $model] = $this->parseModelString($summarizationModel);

            $response = Prism::text()
                ->using($provider, $model)
                ->withSystemPrompt('You are a conversation summarizer. Compress the following conversation history into a concise summary. Preserve: key facts and decisions, tool call outcomes, file/artifact references, active task state and next steps. Omit: verbose tool outputs, redundant confirmations, failed attempts that were superseded. Be concise — aim for 20-30% of the original length.')
                ->withPrompt($olderText)
                ->withMaxTokens($maxTokens)
                ->asText();

            $summary = $response->text;

            return "[Context Summary — older conversation compacted]\n{$summary}\n\n[Recent conversation follows]\n".implode("\n", $recentLines);
        } catch (\Throwable $e) {
            Log::warning('ContextCompactor: summarization failed, skipping stage', [
                'error' => $e->getMessage(),
            ]);

            return $userPrompt;
        }
    }

    /**
     * Stage 3: Sliding window — keep only the most recent portion.
     */
    private function applySlidingWindow(string $userPrompt, int $minPreservedLines): string
    {
        $lines = explode("\n", $userPrompt);

        if (count($lines) <= $minPreservedLines * 3) {
            return $userPrompt;
        }

        // Keep the last ~40% of lines or minPreservedLines*3, whichever is larger
        $keepCount = max($minPreservedLines * 3, (int) floor(count($lines) * 0.3));
        $recentLines = array_slice($lines, -$keepCount);

        return "[Earlier conversation history truncated — sliding window applied]\n\n".implode("\n", $recentLines);
    }

    /**
     * Stage 4: Emergency truncation — hard cut from the start to fit the target.
     */
    private function truncateToFit(string $systemPrompt, string $userPrompt, int $targetTokens): string
    {
        $systemTokens = $this->tokenEstimator->estimate($systemPrompt) + 8;
        $availableTokens = $targetTokens - $systemTokens;

        if ($availableTokens <= 0) {
            return '';
        }

        // Estimate how many characters we can keep
        $maxChars = (int) ($availableTokens * 4); // inverse of the estimation ratio

        if (mb_strlen($userPrompt) <= $maxChars) {
            return $userPrompt;
        }

        // Keep the tail (most recent content)
        $truncated = mb_substr($userPrompt, -$maxChars);

        // Try to start at a line boundary
        $firstNewline = strpos($truncated, "\n");
        if ($firstNewline !== false && $firstNewline < 200) {
            $truncated = substr($truncated, $firstNewline + 1);
        }

        return "[Emergency: context truncated to fit token limit]\n\n".$truncated;
    }

    private function isWithinBudget(string $systemPrompt, string $userPrompt, int $targetTokens): bool
    {
        return $this->tokenEstimator->estimateRequest($systemPrompt, $userPrompt) <= $targetTokens;
    }

    /**
     * Parse a "provider/model" string into [provider, model].
     *
     * @return array{string, string}
     */
    private function parseModelString(string $modelString): array
    {
        if (str_contains($modelString, '/')) {
            return explode('/', $modelString, 2);
        }

        return ['anthropic', $modelString];
    }
}
