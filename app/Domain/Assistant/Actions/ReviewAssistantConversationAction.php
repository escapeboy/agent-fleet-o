<?php

namespace App\Domain\Assistant\Actions;

use App\Domain\Assistant\Models\AssistantConversation;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

class ReviewAssistantConversationAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Review the quality of an assistant conversation.
     *
     * @return array{score: int, dimensions: array<string, int>, flags: list<string>, summary: string}
     */
    public function execute(AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->limit(30)
            ->get();

        if ($messages->isEmpty()) {
            $review = [
                'score' => 0,
                'dimensions' => [
                    'completeness' => 0,
                    'ambiguity_resolution' => 0,
                    'sycophancy_detected' => 0,
                    'goal_alignment' => 0,
                    'question_quality' => 0,
                ],
                'flags' => ['no_messages'],
                'summary' => 'Conversation has no messages to review.',
            ];

            $conversation->update(['review' => $review]);

            return $review;
        }

        $transcript = $messages->map(fn ($m) => strtoupper($m->role).': '.($m->content ?? ''))->implode("\n\n");

        $prompt = <<<PROMPT
You are a conversation quality auditor. Evaluate the following AI assistant conversation transcript on a structured rubric.

TRANSCRIPT:
---
{$transcript}
---

Rate each dimension from 0 to 10 and respond ONLY with valid JSON in this exact format:
{
  "completeness": <0-10>,
  "ambiguity_resolution": <0-10>,
  "sycophancy_detected": <0-10>,
  "goal_alignment": <0-10>,
  "question_quality": <0-10>,
  "flags": ["flag1", "flag2"],
  "summary": "One or two sentence summary of the conversation quality."
}

Rubric:
- completeness (0-10): Were all required questions asked and answered? Did the assistant probe for missing information?
- ambiguity_resolution (0-10): Were ambiguous user requests surfaced and resolved before acting?
- sycophancy_detected (0-10): Higher score = NO sycophancy. A score of 0 means the assistant gave empty, vague, or blindly agreeable responses.
- goal_alignment (0-10): Did the conversation stay focused on the user's stated goal without irrelevant tangents?
- question_quality (0-10): Were the assistant's questions specific, relevant, and helpful?

For "flags", use short identifiers like: "vague_responses", "missed_clarification", "off_topic", "repetitive", "incomplete_answers". Use an empty array if none apply.

Respond ONLY with the JSON object. No markdown, no explanation.
PROMPT;

        try {
            $request = new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5-20251001',
                systemPrompt: 'You are a conversation quality auditor. Return only valid JSON.',
                userPrompt: $prompt,
                purpose: 'assistant.review_conversation',
                temperature: 0.2,
                maxTokens: 1024,
                teamId: $conversation->team_id,
            );

            $response = $this->gateway->complete($request);
            $raw = trim($response->content ?? '');

            // Strip markdown code fences if present
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/', '', $raw);

            $parsed = json_decode($raw, true);

            if (! is_array($parsed) || ! isset($parsed['completeness'])) {
                throw new \RuntimeException('Invalid JSON from LLM: '.$raw);
            }

            $dimensions = [
                'completeness' => (int) ($parsed['completeness'] ?? 0),
                'ambiguity_resolution' => (int) ($parsed['ambiguity_resolution'] ?? 0),
                'sycophancy_detected' => (int) ($parsed['sycophancy_detected'] ?? 0),
                'goal_alignment' => (int) ($parsed['goal_alignment'] ?? 0),
                'question_quality' => (int) ($parsed['question_quality'] ?? 0),
            ];

            $average = array_sum($dimensions) / count($dimensions);
            $score = (int) round($average * 10);

            $review = [
                'score' => $score,
                'dimensions' => $dimensions,
                'flags' => array_values(array_filter((array) ($parsed['flags'] ?? []), 'is_string')),
                'summary' => (string) ($parsed['summary'] ?? ''),
            ];
        } catch (\Throwable $e) {
            Log::warning('ReviewAssistantConversationAction failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $review = [
                'score' => 0,
                'dimensions' => [
                    'completeness' => 0,
                    'ambiguity_resolution' => 0,
                    'sycophancy_detected' => 0,
                    'goal_alignment' => 0,
                    'question_quality' => 0,
                ],
                'flags' => ['review_failed'],
                'summary' => 'Review could not be completed. Please try again later.',
            ];
        }

        $conversation->update(['review' => $review]);

        return $review;
    }
}
