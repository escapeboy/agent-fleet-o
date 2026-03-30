<?php

namespace App\Domain\Crew\Actions;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class GenerateCrewFromPromptAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Generate a structured crew definition from a natural language goal.
     *
     * @return array{crew_name: string, description: string, process_type: string, coordinator: array, qa_agent: array, workers: array, suggested_quality_threshold: float, reasoning: string}
     */
    public function execute(string $goal, ?string $teamId = null): array
    {
        $team = $teamId ? Team::find($teamId) : null;
        $resolved = $this->providerResolver->resolve(team: $team);

        // Prefer fast/cheap Haiku for crew design generation
        $provider = $resolved['provider'];
        $model = str_contains($resolved['provider'], 'anthropic') ? 'claude-haiku-4-5-20251001' : $resolved['model'];

        $systemPrompt = $this->buildSystemPrompt();

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $goal,
                maxTokens: 2048,
                temperature: 0.4,
                purpose: 'crew.generate_from_prompt',
                teamId: $teamId,
            ));

            $parsed = $this->parseResponse($response->content);

            if (! $parsed) {
                throw new \RuntimeException('Failed to parse LLM response into crew structure.');
            }

            return $parsed;
        } catch (\Throwable $e) {
            Log::error('GenerateCrewFromPromptAction: LLM call failed', [
                'error' => $e->getMessage(),
                'goal' => $goal,
            ]);

            throw $e;
        }
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a crew architect for multi-agent AI systems. Given a natural language goal, design an optimal crew of AI agents.

## Your Task

Analyze the goal and design:
1. A coordinator agent that orchestrates and synthesizes
2. A QA agent that validates outputs
3. 1-4 specialist worker agents

## Process Types
- **hierarchical**: Coordinator delegates tasks to workers, QA validates each output (default, best for most goals)
- **sequential**: Workers execute in sequence, each building on the previous output
- **parallel**: Workers execute simultaneously on different aspects of the goal

## Output Format

Return ONLY valid JSON with this exact structure (no markdown, no explanation):
{
  "crew_name": "Short descriptive name for the crew",
  "description": "What this crew achieves and how",
  "process_type": "hierarchical|sequential|parallel",
  "coordinator": {
    "role": "Coordinator role title",
    "goal": "What this coordinator does",
    "backstory": "Relevant expertise and approach",
    "skills": ["skill1", "skill2"]
  },
  "qa_agent": {
    "role": "QA role title",
    "goal": "What this QA agent validates",
    "backstory": "Quality standards and review approach",
    "skills": ["skill1", "skill2"]
  },
  "workers": [
    {
      "role": "Worker role title",
      "goal": "Specific responsibility of this worker",
      "backstory": "Relevant expertise",
      "skills": ["skill1", "skill2"],
      "context_scope": ["goal", "prior_outputs"]
    }
  ],
  "suggested_quality_threshold": 0.75,
  "reasoning": "Brief explanation of why this structure was chosen for the goal"
}

## Rules
1. Use 2-4 workers (not more)
2. Skills should be actionable capability names (e.g. "web_research", "data_analysis", "content_writing")
3. suggested_quality_threshold should be between 0.6 (exploratory) and 0.95 (critical)
4. context_scope values: "goal", "prior_outputs", "feedback", "coordinator_directive"
5. Keep roles specific and non-overlapping
PROMPT;
    }

    private function parseResponse(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```\s*$/', '', $text);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GenerateCrewFromPromptAction: Invalid JSON response', [
                'error' => json_last_error_msg(),
                'text_preview' => substr($text, 0, 200),
            ]);

            return null;
        }

        // Validate required keys
        $required = ['crew_name', 'process_type', 'coordinator', 'qa_agent', 'workers'];
        foreach ($required as $key) {
            if (! isset($decoded[$key])) {
                Log::warning('GenerateCrewFromPromptAction: Missing required key in response', ['key' => $key]);

                return null;
            }
        }

        if (! is_array($decoded['workers']) || count($decoded['workers']) === 0) {
            return null;
        }

        return $decoded;
    }
}
