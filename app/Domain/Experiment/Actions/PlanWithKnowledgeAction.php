<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\KnowledgeGraph\Actions\SearchKgFactsAction;
use App\Domain\Memory\Models\Memory;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class PlanWithKnowledgeAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
        private readonly SearchKgFactsAction $searchKgFacts,
    ) {}

    /**
     * Enrich a planning goal with three layers of context:
     * 1. Memory — past experiment outcomes relevant to the goal
     * 2. KnowledgeGraph — domain facts related to the goal
     * 3. First-principles LLM reasoning — insights and risks from original analysis
     *
     * @return array{memory_hits: array, kg_hits: array, first_principles: array, enriched_context: string}
     */
    public function execute(string $goal, string $teamId): array
    {
        $memoryHits = $this->searchMemory($goal, $teamId);
        $kgHits = $this->searchKnowledgeGraph($goal, $teamId);
        $firstPrinciples = $this->runFirstPrinciplesReasoning($goal, $teamId, $memoryHits, $kgHits);

        return [
            'memory_hits' => $memoryHits,
            'kg_hits' => $kgHits,
            'first_principles' => $firstPrinciples,
            'enriched_context' => $this->buildEnrichedContext($memoryHits, $kgHits, $firstPrinciples),
        ];
    }

    /**
     * Layer 1: Search Memory for relevant past outcomes.
     *
     * @return array<int, array{content: string, importance: float}>
     */
    private function searchMemory(string $goal, string $teamId): array
    {
        try {
            $memories = Memory::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->orderByDesc('importance')
                ->limit(5)
                ->get(['content', 'importance']);

            return $memories->map(fn ($m) => [
                'content' => $m->content,
                'importance' => $m->importance ?? 0.5,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            Log::debug('PlanWithKnowledgeAction: Memory search skipped', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Layer 2: Search KnowledgeGraph for domain facts.
     *
     * @return array<int, array{fact: string, source: string|null, relation_type: string|null}>
     */
    private function searchKnowledgeGraph(string $goal, string $teamId): array
    {
        try {
            $facts = $this->searchKgFacts->execute(
                teamId: $teamId,
                query: $goal,
                limit: 5,
            );

            return $facts->map(fn ($edge) => [
                'fact' => $edge->fact,
                'source' => $edge->sourceEntity?->name,
                'relation_type' => $edge->relation_type,
            ])->values()->toArray();
        } catch (\Throwable $e) {
            Log::debug('PlanWithKnowledgeAction: KG search skipped', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Layer 3: First-principles LLM reasoning using layers 1+2 as context.
     *
     * @param  array<int, array{content: string, importance: float}>  $memoryHits
     * @param  array<int, array{fact: string, source: string|null, relation_type: string|null}>  $kgHits
     * @return array{insights: array, risks: array, key_questions: array}
     */
    private function runFirstPrinciplesReasoning(
        string $goal,
        string $teamId,
        array $memoryHits,
        array $kgHits,
    ): array {
        try {
            $memoryContext = empty($memoryHits)
                ? '(no relevant past experience found)'
                : collect($memoryHits)->map(fn ($m) => '- '.$m['content'])->implode("\n");

            $kgContext = empty($kgHits)
                ? '(no relevant domain knowledge found)'
                : collect($kgHits)->map(fn ($k) => '- '.$k['fact'])->implode("\n");

            $userPrompt = <<<TEXT
Given this goal: {$goal}

Relevant past context:
{$memoryContext}

Domain knowledge:
{$kgContext}

Based on the above AND your first-principles reasoning (prioritize original analysis over convention),
what are the key considerations, risks, and non-obvious insights that should inform planning this goal?
Be specific. Flag anything the conventional approach would miss.

Output JSON: {"insights": ["...", "..."], "risks": ["..."], "key_questions": ["..."]}
TEXT;

            $resolved = $this->providerResolver->resolve(team: null);

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: 'claude-haiku-4-5-20251001',
                systemPrompt: 'You are a strategic planning assistant. Analyze goals using first-principles thinking. Always output valid JSON.',
                userPrompt: $userPrompt,
                maxTokens: 1024,
                teamId: $teamId,
                purpose: 'planning.knowledge_enrichment',
                temperature: 0.5,
            ));

            return $this->parseFirstPrinciples($response->content);
        } catch (\Throwable $e) {
            Log::debug('PlanWithKnowledgeAction: First-principles reasoning skipped', [
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return ['insights' => [], 'risks' => [], 'key_questions' => []];
        }
    }

    /**
     * Parse the first-principles JSON response.
     *
     * @return array{insights: array, risks: array, key_questions: array}
     */
    private function parseFirstPrinciples(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            return ['insights' => [], 'risks' => [], 'key_questions' => []];
        }

        return [
            'insights' => (array) ($parsed['insights'] ?? []),
            'risks' => (array) ($parsed['risks'] ?? []),
            'key_questions' => (array) ($parsed['key_questions'] ?? []),
        ];
    }

    /**
     * Build a formatted enriched context string for injection into other prompts.
     *
     * @param  array<int, array{content: string, importance: float}>  $memoryHits
     * @param  array<int, array{fact: string, source: string|null, relation_type: string|null}>  $kgHits
     * @param  array{insights: array, risks: array, key_questions: array}  $firstPrinciples
     */
    private function buildEnrichedContext(array $memoryHits, array $kgHits, array $firstPrinciples): string
    {
        $parts = [];

        if (! empty($memoryHits)) {
            $parts[] = "Past experience:\n".collect($memoryHits)->map(fn ($m) => '- '.$m['content'])->implode("\n");
        }

        if (! empty($kgHits)) {
            $parts[] = "Domain knowledge:\n".collect($kgHits)->map(fn ($k) => '- '.$k['fact'])->implode("\n");
        }

        if (! empty($firstPrinciples['insights'])) {
            $parts[] = "Key insights:\n".collect($firstPrinciples['insights'])->map(fn ($i) => '- '.$i)->implode("\n");
        }

        if (! empty($firstPrinciples['risks'])) {
            $parts[] = "Risks to watch:\n".collect($firstPrinciples['risks'])->map(fn ($r) => '- '.$r)->implode("\n");
        }

        if (! empty($firstPrinciples['key_questions'])) {
            $parts[] = "Key questions to address:\n".collect($firstPrinciples['key_questions'])->map(fn ($q) => '- '.$q)->implode("\n");
        }

        return implode("\n\n", $parts);
    }
}
