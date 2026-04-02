<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Enums\RequestComplexity;

class ComplexityClassifier
{
    /**
     * Classify request complexity using sub-millisecond heuristics (no LLM call).
     *
     * Signals: tool count, max tokens, system prompt length, explicit hint.
     * The classification can be overridden by setting $request->complexity directly.
     */
    public function classify(AiRequestDTO $request): RequestComplexity
    {
        if ($request->complexity) {
            return $request->complexity;
        }

        $signals = [
            $this->classifyByToolCount($request),
            $this->classifyByMaxTokens($request),
            $this->classifyByPromptLength($request),
        ];

        // Take the highest complexity from all signals
        $maxWeight = 0;
        $result = RequestComplexity::Light;

        foreach ($signals as $signal) {
            if ($signal->weight() > $maxWeight) {
                $maxWeight = $signal->weight();
                $result = $signal;
            }
        }

        return $result;
    }

    private function classifyByToolCount(AiRequestDTO $request): RequestComplexity
    {
        $toolCount = $request->tools ? count($request->tools) : 0;

        $thresholds = config('ai_routing.complexity_thresholds.tool_count', [
            'standard' => 3,
            'heavy' => 11,
        ]);

        if ($toolCount >= $thresholds['heavy']) {
            return RequestComplexity::Heavy;
        }

        if ($toolCount >= $thresholds['standard']) {
            return RequestComplexity::Standard;
        }

        return RequestComplexity::Light;
    }

    private function classifyByMaxTokens(AiRequestDTO $request): RequestComplexity
    {
        $thresholds = config('ai_routing.complexity_thresholds.max_tokens', [
            'standard' => 1024,
            'heavy' => 4096,
        ]);

        if ($request->maxTokens >= $thresholds['heavy']) {
            return RequestComplexity::Heavy;
        }

        if ($request->maxTokens >= $thresholds['standard']) {
            return RequestComplexity::Standard;
        }

        return RequestComplexity::Light;
    }

    private function classifyByPromptLength(AiRequestDTO $request): RequestComplexity
    {
        // Rough token estimate: ~4 chars per token
        $estimatedTokens = (int) (strlen($request->systemPrompt) / 4);

        $thresholds = config('ai_routing.complexity_thresholds.prompt_tokens', [
            'standard' => 2000,
            'heavy' => 8000,
        ]);

        if ($estimatedTokens >= $thresholds['heavy']) {
            return RequestComplexity::Heavy;
        }

        if ($estimatedTokens >= $thresholds['standard']) {
            return RequestComplexity::Standard;
        }

        return RequestComplexity::Light;
    }
}
