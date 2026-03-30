<?php

namespace App\Domain\Evolution\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;

class AnalyzeExecutionForEvolutionAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function execute(Agent $agent, ?AgentExecution $execution = null): EvolutionProposal
    {
        $context = $this->buildAnalysisContext($agent, $execution);

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $agent->provider,
            model: $agent->model,
            systemPrompt: $this->buildSystemPrompt(),
            userPrompt: $context,
            teamId: $agent->team_id,
            maxTokens: 2000,
            temperature: 0.3,
            purpose: 'agent.evolution_analyze',
        ));

        $parsed = $this->parseResponse($response->content);

        $complexityDelta = $this->computeComplexityDelta($agent, $parsed['changes']);

        return EvolutionProposal::create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'execution_id' => $execution?->id,
            'status' => EvolutionProposalStatus::Pending,
            'analysis' => $parsed['analysis'],
            'proposed_changes' => $parsed['changes'],
            'reasoning' => $parsed['reasoning'],
            'confidence_score' => $parsed['confidence'],
            'complexity_delta' => $complexityDelta,
        ]);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an AI agent performance analyst. Analyze the agent's configuration and execution history to propose specific improvements.

Respond ONLY with valid JSON in this exact format:
{
    "analysis": "Brief analysis of current performance and issues",
    "changes": {
        "goal": "Improved goal text or null if no change needed",
        "backstory": "Improved backstory or null if no change needed",
        "personality": {"tone": "...", "traits": ["..."]}
    },
    "reasoning": "Why these changes would improve performance",
    "confidence": 0.75
}

Rules:
- Only propose changes that would meaningfully improve the agent
- Set fields to null if no change is needed
- Confidence should be 0.0-1.0 based on how certain you are the changes help
- Focus on the agent's role, goal clarity, and behavioral alignment
- Do not change the provider or model
PROMPT;
    }

    private function buildAnalysisContext(Agent $agent, ?AgentExecution $execution): string
    {
        $parts = [
            "Agent: {$agent->name}",
            "Role: {$agent->role}",
            "Goal: {$agent->goal}",
        ];

        if ($agent->backstory) {
            $parts[] = "Backstory: {$agent->backstory}";
        }

        if ($agent->personality) {
            $parts[] = 'Personality: '.json_encode($agent->personality);
        }

        if ($execution) {
            $parts[] = "Last execution status: {$execution->status}";
            if ($execution->error_message) {
                $parts[] = "Error: {$execution->error_message}";
            }
            if ($execution->output) {
                $output = json_encode($execution->output);
                $parts[] = 'Output (truncated): '.mb_substr($output, 0, 500);
            }
        }

        // Include recent execution stats
        $recentExecutions = $agent->executions()->latest()->limit(10)->get();
        if ($recentExecutions->isNotEmpty()) {
            $total = $recentExecutions->count();
            $failed = $recentExecutions->where('status', 'failed')->count();
            $parts[] = "Recent executions: {$total} total, {$failed} failed";
        }

        return implode("\n", $parts);
    }

    /**
     * Compute a token-count delta between the proposed text fields and the agent's current text.
     * Positive = proposed is more complex, negative = simpler.
     *
     * @param  array<string, mixed>  $changes
     */
    private function computeComplexityDelta(Agent $agent, array $changes): int
    {
        $tokenize = fn (string $text): int => count(
            preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [],
        );

        $currentTokens = $tokenize((string) ($agent->goal ?? ''))
            + $tokenize((string) ($agent->backstory ?? ''));

        $proposedGoal = (string) ($changes['goal'] ?? $agent->goal ?? '');
        $proposedBackstory = (string) ($changes['backstory'] ?? $agent->backstory ?? '');

        $proposedTokens = $tokenize($proposedGoal) + $tokenize($proposedBackstory);

        return $proposedTokens - $currentTokens;
    }

    private function parseResponse(string $content): array
    {
        $defaults = [
            'analysis' => 'Unable to parse analysis',
            'changes' => [],
            'reasoning' => null,
            'confidence' => 0.5,
        ];

        // Extract JSON from response (handle markdown code blocks)
        $json = $content;
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $content, $matches)) {
            $json = $matches[1];
        }

        $parsed = json_decode(trim($json), true);
        if (! is_array($parsed)) {
            return array_merge($defaults, ['analysis' => $content]);
        }

        return [
            'analysis' => $parsed['analysis'] ?? $defaults['analysis'],
            'changes' => array_filter($parsed['changes'] ?? [], fn ($v) => $v !== null),
            'reasoning' => $parsed['reasoning'] ?? null,
            'confidence' => min(1.0, max(0.0, (float) ($parsed['confidence'] ?? 0.5))),
        ];
    }
}
