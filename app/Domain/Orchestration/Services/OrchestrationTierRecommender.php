<?php

namespace App\Domain\Orchestration\Services;

use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Orchestration\Enums\OrchestrationTier;

/**
 * Recommends an orchestration shape (single agent / crew / workflow) for a
 * goal. Deterministic, rule-based, zero-cost — and recommendation ONLY: it
 * never executes anything. Heuristics map goal phrasing to the shape that fits
 * among the 7 crew process types and the workflow DAG.
 */
class OrchestrationTierRecommender
{
    /** @var array<string, list<string>> crew-process-type → trigger phrases */
    private const CREW_SIGNALS = [
        CrewProcessType::Fanout->value => ['compare', 'perspective', 'options', 'brainstorm', 'alternativ', 'diverse', 'different angle', 'pros and cons', 'ideas', 'opinions'],
        CrewProcessType::Adversarial->value => ['debate', 'root cause', 'root-cause', 'challenge', 'hypothes', 'adversar', 'diagnose', 'why is', 'why does', 'argue'],
        CrewProcessType::ChatRoom->value => ['consensus', 'check twice', 'verify', 'review', 'validate', 'agree', 'deliberate', 'double-check', 'second opinion'],
    ];

    /** @var list<string> phrases that indicate a multi-stage pipeline → workflow */
    private const WORKFLOW_SIGNALS = ['pipeline', 'step by step', 'step-by-step', ' then ', 'stages', 'multi-stage', 'sequence', 'migrate', 'migration', 'workflow', 'orchestrate', 'first ', 'after that', 'etl', 'each file', 'across the codebase', 'hundreds of'];

    /**
     * @param  array<string, mixed>  $signals  Optional explicit hints:
     *                                         needs_parallel(bool), stages(int), subtasks(int).
     * @return array{tier:string, process_type:?string, reasoning:list<string>, confidence:string, estimated_agents:?int}
     */
    public function recommend(string $goal, array $signals = []): array
    {
        $text = ' '.strtolower(trim($goal)).' ';
        $reasoning = [];

        // --- Crew process-type scoring ---
        $crewScores = [];
        foreach (self::CREW_SIGNALS as $processType => $phrases) {
            $hits = array_values(array_filter($phrases, fn ($p) => str_contains($text, $p)));
            if ($hits !== []) {
                $crewScores[$processType] = count($hits);
                $reasoning[] = "crew/{$processType}: matched ".implode(', ', array_map(fn ($h) => '"'.trim($h).'"', $hits));
            }
        }

        // --- Workflow scoring ---
        $workflowHits = array_values(array_filter(self::WORKFLOW_SIGNALS, fn ($p) => str_contains($text, $p)));
        $workflowScore = count($workflowHits);
        if ($workflowHits !== []) {
            $reasoning[] = 'workflow: matched '.implode(', ', array_map(fn ($h) => '"'.trim($h).'"', $workflowHits));
        }

        // --- Explicit signal overrides ---
        $stages = (int) ($signals['stages'] ?? 0);
        if ($stages > 1) {
            $workflowScore += $stages;
            $reasoning[] = "workflow: {$stages} declared stages";
        }
        if (! empty($signals['needs_parallel'])) {
            $top = $crewScores === [] ? CrewProcessType::Fanout->value : array_key_first($this->sortedDesc($crewScores));
            $crewScores[$top] = ($crewScores[$top] ?? 0) + 2;
            $reasoning[] = 'crew: needs_parallel signal';
        }

        $crewTotal = array_sum($crewScores);

        // --- Decide ---
        if ($crewTotal === 0 && $workflowScore === 0) {
            return $this->result(
                OrchestrationTier::SingleAgent,
                null,
                $reasoning === [] ? ['no parallel/multi-stage/adversarial signals — a single agent fits'] : $reasoning,
                'low',
                null,
            );
        }

        if ($workflowScore > $crewTotal) {
            return $this->result(OrchestrationTier::Workflow, null, $reasoning, $this->confidence($workflowScore, $crewTotal), null);
        }

        // Crew wins (ties favour crew — cheaper to set up than a DAG).
        $best = array_key_first($this->sortedDesc($crewScores));
        $estimatedAgents = (int) ($signals['subtasks'] ?? 3);

        return $this->result(
            OrchestrationTier::Crew,
            $best,
            $reasoning,
            $this->confidence($crewTotal, $workflowScore),
            max(2, $estimatedAgents),
        );
    }

    /**
     * @param  array<string,int>  $scores
     * @return array<string,int>
     */
    private function sortedDesc(array $scores): array
    {
        arsort($scores);

        return $scores;
    }

    private function confidence(int $winner, int $runnerUp): string
    {
        $margin = $winner - $runnerUp;

        return match (true) {
            $winner >= 2 && $margin >= 2 => 'high',
            $margin >= 1 => 'medium',
            default => 'low',
        };
    }

    /**
     * @param  list<string>  $reasoning
     * @return array{tier:string, process_type:?string, reasoning:list<string>, confidence:string, estimated_agents:?int}
     */
    private function result(OrchestrationTier $tier, ?string $processType, array $reasoning, string $confidence, ?int $estimatedAgents): array
    {
        return [
            'tier' => $tier->value,
            'process_type' => $processType,
            'reasoning' => $reasoning,
            'confidence' => $confidence,
            'estimated_agents' => $estimatedAgents,
        ];
    }
}
