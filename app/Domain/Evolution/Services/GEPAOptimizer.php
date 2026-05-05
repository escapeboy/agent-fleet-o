<?php

namespace App\Domain\Evolution\Services;

use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Enums\EvolutionType;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GEPAOptimizer
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    public function run(Skill $skill, int $populationSize = 5): Collection
    {
        $executions = SkillExecution::withoutGlobalScopes()
            ->where('skill_id', $skill->id)
            ->whereNotNull('quality_score')
            ->latest()
            ->take(20)
            ->get();

        if ($executions->count() < 5) {
            return collect();
        }

        $baselineScore = (float) $executions->avg('quality_score');
        $failurePatterns = $executions
            ->whereNotNull('error_message')
            ->pluck('error_message')
            ->take(5)
            ->values();

        $prompt = $this->buildEvolutionPrompt($skill, $baselineScore, $failurePatterns, $populationSize);

        $userId = Team::ownerIdFor($skill->team_id);

        try {
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'You are a prompt optimization expert. Respond with valid JSON only.',
                userPrompt: $prompt,
                maxTokens: 4096,
                teamId: $skill->team_id,
                userId: $userId,
                purpose: 'gepa_optimization',
            ));
        } catch (\Throwable $e) {
            Log::warning('GEPAOptimizer: LLM call failed', ['skill_id' => $skill->id, 'error' => $e->getMessage()]);

            return collect();
        }

        $data = json_decode($response->content, true);
        $variants = $data['variants'] ?? [];

        $proposals = collect();
        foreach (array_slice($variants, 0, $populationSize) as $variant) {
            if (empty($variant['system_prompt'])) {
                continue;
            }

            $proposal = EvolutionProposal::create([
                'team_id' => $skill->team_id,
                'skill_id' => $skill->id,
                'evolution_type' => EvolutionType::SkillMutation,
                'status' => EvolutionProposalStatus::Pending,
                'trigger' => 'gepa_cycle',
                'analysis' => "GEPA optimization cycle. Baseline score: {$baselineScore}",
                'proposed_changes' => ['system_prompt' => $variant['system_prompt']],
                'reasoning' => $variant['reasoning'] ?? '',
                'confidence_score' => 0.0,
                'mutation_variant' => [
                    'strategy' => $variant['strategy'] ?? 'unknown',
                    'parent_score' => $baselineScore,
                    'candidate_score' => null,
                ],
            ]);

            $proposals->push($proposal);
        }

        return $proposals;
    }

    private function buildEvolutionPrompt(Skill $skill, float $baselineScore, Collection $failurePatterns, int $populationSize): string
    {
        $failuresText = $failurePatterns->isNotEmpty() ? $failurePatterns->join('; ') : 'none';

        return <<<PROMPT
Skill: {$skill->name}
Description: {$skill->description}
Current system prompt:
{$skill->system_prompt}

Baseline quality score: {$baselineScore}/1.0
Failure patterns: {$failuresText}

Generate {$populationSize} improved system prompt variants. Each must use a different strategy from: add_examples, rephrase_goal, add_constraints, simplify, chain_of_thought

Return JSON:
{
  "variants": [
    {
      "system_prompt": "...",
      "strategy": "add_examples",
      "reasoning": "..."
    }
  ]
}
PROMPT;
    }
}
