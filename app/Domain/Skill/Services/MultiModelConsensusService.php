<?php

namespace App\Domain\Skill\Services;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;

class MultiModelConsensusService
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Run a 3-stage LLM council:
     *   Stage 1 — Parallel generation: each model answers independently
     *   Stage 2 — Anonymous peer review: each model critiques others' responses
     *   Stage 3 — Judge synthesis: a designated judge synthesizes all perspectives
     *
     * @param  array<array{provider: string, model: string}>  $models
     * @param  array{provider: string, model: string}  $judgeModel
     * @return array{response: AiResponseDTO, confidence_score: float, consensus_level: string, peer_reviews: array, dissenting_view: string|null}
     */
    public function run(
        string $prompt,
        string $systemPrompt,
        array $models,
        array $judgeModel,
        string $teamId,
        string $userId,
        ?string $experimentId = null,
        ?string $agentId = null,
    ): array {
        // Stage 1: Independent generation
        $generations = $this->stageGenerate($prompt, $systemPrompt, $models, $teamId, $userId, $experimentId, $agentId);

        // Stage 2: Anonymous peer review
        $peerReviews = $this->stagePeerReview($prompt, $generations, $models, $teamId, $userId, $experimentId, $agentId);

        // Stage 3: Judge synthesis
        $judgeResult = $this->stageJudge($prompt, $systemPrompt, $generations, $peerReviews, $judgeModel, $teamId, $userId, $experimentId, $agentId);

        return $judgeResult;
    }

    /**
     * Stage 1: Each model generates an independent answer.
     *
     * @return array<array{label: string, provider: string, model: string, response: AiResponseDTO}>
     */
    private function stageGenerate(
        string $prompt,
        string $systemPrompt,
        array $models,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?string $agentId,
    ): array {
        $results = [];

        foreach ($models as $i => $modelConfig) {
            $label = 'Response '.chr(65 + $i); // A, B, C

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $modelConfig['provider'],
                model: $modelConfig['model'],
                systemPrompt: $systemPrompt ?: 'You are a helpful expert. Provide a thorough, accurate response.',
                userPrompt: $prompt,
                maxTokens: 2048,
                userId: $userId,
                teamId: $teamId,
                experimentId: $experimentId,
                agentId: $agentId,
                purpose: 'consensus:generate:'.$label,
                temperature: 0.7,
            ));

            $results[] = [
                'label' => $label,
                'provider' => $modelConfig['provider'],
                'model' => $modelConfig['model'],
                'response' => $response,
            ];
        }

        return $results;
    }

    /**
     * Stage 2: Each model reviews other models' responses anonymously.
     * Model names are NEVER revealed (prevents self-model bias).
     *
     * @param  array<array{label: string, provider: string, model: string, response: AiResponseDTO}>  $generations
     * @return array<string, string> label => review text
     */
    private function stagePeerReview(
        string $originalPrompt,
        array $generations,
        array $models,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?string $agentId,
    ): array {
        $reviews = [];

        foreach ($models as $i => $modelConfig) {
            $reviewerLabel = 'Response '.chr(65 + $i);

            // Build context with OTHER models' responses only (anonymous labels)
            $otherResponses = array_filter($generations, fn ($g) => $g['label'] !== $reviewerLabel);
            $otherText = implode("\n\n", array_map(
                fn ($g) => "### {$g['label']}\n{$g['response']->content}",
                $otherResponses,
            ));

            $reviewPrompt = "Original question:\n{$originalPrompt}\n\nReview these responses:\n\n{$otherText}\n\nFor each response, provide:\n1. A score from 1-10\n2. Key strengths\n3. Key weaknesses or factual errors\n4. Missing information";

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $modelConfig['provider'],
                model: $modelConfig['model'],
                systemPrompt: 'You are an objective critical evaluator. Assess each response for accuracy, completeness, and quality. Do not reveal which AI produced each response.',
                userPrompt: $reviewPrompt,
                maxTokens: 1024,
                userId: $userId,
                teamId: $teamId,
                experimentId: $experimentId,
                agentId: $agentId,
                purpose: 'consensus:peer_review:'.$reviewerLabel,
                temperature: 0.3,
            ));

            $reviews[$reviewerLabel] = $response->content;
        }

        return $reviews;
    }

    /**
     * Stage 3: Judge model synthesizes all generations and peer reviews.
     *
     * @param  array<array{label: string, provider: string, model: string, response: AiResponseDTO}>  $generations
     * @param  array<string, string>  $peerReviews
     * @param  array{provider: string, model: string}  $judgeModel
     */
    private function stageJudge(
        string $originalPrompt,
        string $systemPrompt,
        array $generations,
        array $peerReviews,
        array $judgeModel,
        string $teamId,
        string $userId,
        ?string $experimentId,
        ?string $agentId,
    ): array {
        $allResponses = implode("\n\n", array_map(
            fn ($g) => "### {$g['label']}\n{$g['response']->content}",
            $generations,
        ));

        $allReviews = implode("\n\n", array_map(
            fn ($label, $review) => "### Peer reviews by the model that produced {$label}:\n{$review}",
            array_keys($peerReviews),
            $peerReviews,
        ));

        $judgePrompt = "Original question:\n{$originalPrompt}\n\n"
            ."Independent responses from three models:\n{$allResponses}\n\n"
            ."Peer review critiques:\n{$allReviews}\n\n"
            ."Based on all of the above, synthesize the single best answer. Also provide:\n"
            ."- confidence_score: a number from 0.0 to 1.0 (1.0 = all models agreed perfectly)\n"
            ."- consensus_level: 'strong' (all agree), 'moderate' (mostly agree), or 'weak' (significant disagreement)\n"
            ."- dissenting_view: if there was a notable minority view worth preserving, summarize it (or null)\n\n"
            .'Respond in JSON with keys: answer, confidence_score, consensus_level, dissenting_view';

        $judgeResponse = $this->gateway->complete(new AiRequestDTO(
            provider: $judgeModel['provider'],
            model: $judgeModel['model'],
            systemPrompt: $systemPrompt ?: 'You are the final arbiter. Synthesize the best answer from multiple independent perspectives.',
            userPrompt: $judgePrompt,
            maxTokens: 4096,
            userId: $userId,
            teamId: $teamId,
            experimentId: $experimentId,
            agentId: $agentId,
            purpose: 'consensus:judge',
            temperature: 0.2,
        ));

        // Parse structured judge output
        $parsed = $this->parseJudgeOutput($judgeResponse->content);

        // Sum all usage costs across all model calls
        $totalCost = $judgeResponse->usage->costCredits;
        foreach ($generations as $gen) {
            $totalCost += $gen['response']->usage->costCredits;
        }

        // Build a final AiResponseDTO with the synthesized answer
        $finalResponse = new AiResponseDTO(
            content: $parsed['answer'],
            parsedOutput: $parsed,
            usage: new AiUsageDTO(
                promptTokens: $judgeResponse->usage->promptTokens,
                completionTokens: $judgeResponse->usage->completionTokens,
                costCredits: $totalCost,
            ),
            provider: $judgeModel['provider'],
            model: $judgeModel['model'],
            latencyMs: $judgeResponse->latencyMs,
        );

        return [
            'response' => $finalResponse,
            'confidence_score' => (float) ($parsed['confidence_score'] ?? 0.5),
            'consensus_level' => $parsed['consensus_level'] ?? 'moderate',
            'peer_reviews' => $peerReviews,
            'dissenting_view' => $parsed['dissenting_view'] ?? null,
        ];
    }

    private function parseJudgeOutput(string $content): array
    {
        // Strip markdown code fences if present
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $content);
        $cleaned = preg_replace('/\s*```$/m', '', $cleaned ?? $content);

        $decoded = json_decode(trim($cleaned ?? $content), true);

        if (is_array($decoded) && isset($decoded['answer'])) {
            return $decoded;
        }

        // Fallback: treat entire response as the answer with moderate confidence
        return [
            'answer' => $content,
            'confidence_score' => 0.6,
            'consensus_level' => 'moderate',
            'dissenting_view' => null,
        ];
    }
}
