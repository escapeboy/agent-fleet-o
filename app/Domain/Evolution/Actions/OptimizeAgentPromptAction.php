<?php

namespace App\Domain\Evolution\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evolution\Enums\EvolutionProposalStatus;
use App\Domain\Evolution\Enums\EvolutionType;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

/**
 * Agent-level GEPA prompt optimisation (EvoAgentX borrow), closed through the
 * eval-gate. Mirrors GEPAOptimizer (which evolves Skills) one level up for
 * Agents: generate N candidate identity variants, score EACH against the
 * agent's eval dataset via ReplayEvaluationDatasetAction, and emit an
 * EvolutionProposal for the single best variant ONLY if it beats the current
 * config's baseline by the configured margin. The scoring IS the gate — a
 * regression is never proposed.
 *
 * Reuses existing machinery: the eval dataset (config.eval_gate_dataset_id,
 * shared with EvaluateAgentConfigGateAction), ReplayEvaluationDatasetAction as
 * the fitness function, and ApplyEvolutionProposalAction (which already applies
 * agent goal/backstory) for human-in-the-loop promotion. Opt-in behind
 * agent.prompt_optimizer.enabled; proposal-only (never auto-applies).
 */
class OptimizeAgentPromptAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ReplayEvaluationDatasetAction $replay,
    ) {}

    /**
     * @return array{status: string, message: string, proposal_id?: string, baseline_score?: float, best_score?: float, evaluated?: int}
     */
    public function execute(Agent $agent, ?int $populationSize = null): array
    {
        if (! config('agent.prompt_optimizer.enabled')) {
            return ['status' => 'disabled', 'message' => 'Agent prompt optimizer is disabled.'];
        }

        if ($agent->team_id === null) {
            return ['status' => 'no_team', 'message' => 'Agent has no team.'];
        }

        $datasetId = $agent->config['eval_gate_dataset_id'] ?? null;
        if (! is_string($datasetId) || $datasetId === '') {
            return ['status' => 'no_dataset', 'message' => 'Agent has no eval dataset (config.eval_gate_dataset_id) to optimise against.'];
        }

        $populationSize = max(1, min(8, $populationSize ?? (int) config('agent.prompt_optimizer.population_size', 3)));

        $baseline = $this->score($agent, $datasetId, $this->systemPrompt($agent->role, $agent->goal, $agent->backstory));
        if ($baseline === null) {
            return ['status' => 'eval_unavailable', 'message' => 'Could not establish a baseline score (eval replay unavailable).'];
        }

        $variants = $this->generateVariants($agent, $baseline, $populationSize);
        if ($variants === []) {
            return ['status' => 'no_variants', 'message' => 'No candidate variants were generated.', 'baseline_score' => $baseline];
        }

        $minImprovement = (float) config('agent.prompt_optimizer.min_improvement', 0.0);

        $best = null;
        $evaluated = 0;
        foreach ($variants as $variant) {
            $candidateBackstory = is_string($variant['backstory'] ?? null) && $variant['backstory'] !== '' ? $variant['backstory'] : $agent->backstory;
            $candidateGoal = is_string($variant['goal'] ?? null) && $variant['goal'] !== '' ? $variant['goal'] : $agent->goal;

            $score = $this->score($agent, $datasetId, $this->systemPrompt($agent->role, $candidateGoal, $candidateBackstory));
            if ($score === null) {
                continue;
            }
            $evaluated++;

            if ($score >= $baseline + $minImprovement && ($best === null || $score > $best['score'])) {
                $best = [
                    'score' => $score,
                    'goal' => $candidateGoal,
                    'backstory' => $candidateBackstory,
                    'strategy' => is_string($variant['strategy'] ?? null) ? $variant['strategy'] : 'unknown',
                    'reasoning' => is_string($variant['reasoning'] ?? null) ? $variant['reasoning'] : '',
                ];
            }
        }

        if ($best === null) {
            return [
                'status' => 'no_improvement',
                'message' => "No variant beat baseline {$baseline} by the required margin.",
                'baseline_score' => $baseline,
                'evaluated' => $evaluated,
            ];
        }

        $changes = ['backstory' => $best['backstory']];
        if ($best['goal'] !== $agent->goal && is_string($best['goal']) && $best['goal'] !== '') {
            $changes['goal'] = $best['goal'];
        }

        $proposal = EvolutionProposal::create([
            'team_id' => $agent->team_id,
            'agent_id' => $agent->id,
            'evolution_type' => EvolutionType::AgentConfig,
            'status' => EvolutionProposalStatus::Pending,
            'trigger' => 'prompt_optimizer',
            'analysis' => "GEPA agent prompt optimisation: baseline {$baseline}, best {$best['score']} over {$evaluated} evaluated variant(s).",
            'proposed_changes' => $changes,
            'reasoning' => $best['reasoning'],
            'confidence_score' => round(min(1.0, max(0.0, $best['score'])), 2),
            'mutation_variant' => [
                'strategy' => $best['strategy'],
                'parent_score' => $baseline,
                'candidate_score' => $best['score'],
            ],
        ]);

        return [
            'status' => 'proposed',
            'message' => "Proposed an improved prompt (score {$best['score']} > baseline {$baseline}). Review and apply to promote.",
            'proposal_id' => $proposal->id,
            'baseline_score' => $baseline,
            'best_score' => $best['score'],
            'evaluated' => $evaluated,
        ];
    }

    private function score(Agent $agent, string $datasetId, ?string $systemPrompt): ?float
    {
        $provider = $agent->provider;
        $model = $agent->model;
        if ($provider === '' || $model === '') {
            return null;
        }

        try {
            $run = $this->replay->execute(
                teamId: (string) $agent->team_id,
                datasetId: $datasetId,
                targetProvider: $provider,
                targetModel: $model,
                systemPrompt: $systemPrompt,
                criteria: $this->criteria($agent),
            );
        } catch (\Throwable $e) {
            Log::warning('OptimizeAgentPrompt: replay failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $summary = $run->getAttribute('summary');
        $summary = is_array($summary) ? $summary : [];

        return isset($summary['overall_avg_score']) ? (float) $summary['overall_avg_score'] : null;
    }

    /**
     * @return list<string>
     */
    private function criteria(Agent $agent): array
    {
        // getAttribute (mixed) — the magic accessor is inferred string|null by
        // larastan despite the runtime array cast.
        $criteria = $agent->getAttribute('evaluation_criteria');
        if (! is_array($criteria) || $criteria === []) {
            return ['correctness', 'relevance'];
        }

        return array_values(array_filter($criteria, fn ($c) => is_string($c) && $c !== ''));
    }

    private function systemPrompt(?string $role, ?string $goal, ?string $backstory): ?string
    {
        $parts = array_filter([$role, $goal, $backstory], fn ($v) => is_string($v) && $v !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateVariants(Agent $agent, float $baseline, int $populationSize): array
    {
        $prompt = $this->buildEvolutionPrompt($agent, $baseline, $populationSize);

        try {
            // Cheap meta-model for the optimisation reasoning, mirroring GEPAOptimizer.
            $response = $this->gateway->complete(new AiRequestDTO(
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                systemPrompt: 'You are a prompt optimization expert. Respond with valid JSON only.',
                userPrompt: $prompt,
                maxTokens: 4096,
                teamId: $agent->team_id,
                userId: Team::ownerIdFor($agent->team_id),
                purpose: 'agent_prompt_optimization',
            ));
        } catch (\Throwable $e) {
            Log::warning('OptimizeAgentPrompt: variant generation failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $data = $this->decodeJson($response->content);
        $variants = $data['variants'] ?? null;
        if (! is_array($variants)) {
            return [];
        }

        $out = [];
        foreach (array_slice($variants, 0, $populationSize) as $variant) {
            if (is_array($variant)) {
                $out[] = $variant;
            }
        }

        return $out;
    }

    private function buildEvolutionPrompt(Agent $agent, float $baseline, int $populationSize): string
    {
        $role = $agent->role ?? '';
        $goal = $agent->goal ?? '';
        $backstory = $agent->backstory ?? '';

        return <<<PROMPT
Agent: {$agent->name}
Role: {$role}
Current goal:
{$goal}

Current backstory (the bulk of the system prompt):
{$backstory}

Baseline evaluation score: {$baseline}

Generate {$populationSize} improved variants of this agent's goal and backstory. Each must use a different strategy from: add_examples, sharpen_goal, add_constraints, simplify, chain_of_thought. Keep the agent's identity and purpose; improve clarity and task performance.

Return JSON only:
{
  "variants": [
    {
      "goal": "...",
      "backstory": "...",
      "strategy": "sharpen_goal",
      "reasoning": "..."
    }
  ]
}
PROMPT;
    }

    /**
     * Tolerant JSON decode — strips a leading ```json / ``` fence that some
     * providers wrap around the object before decoding.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $content): array
    {
        $trimmed = trim($content);
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;

        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : [];
    }
}
