<?php

namespace App\Domain\Project\Actions;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class ExpandProjectGoalAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Decompose a project goal into individual features/experiments.
     *
     * @return array{features: array, cost_estimate: float}
     */
    public function execute(string $goal, string $teamId, ?string $context = null): array
    {
        $resolved = $this->providerResolver->resolveDefault($teamId);

        $systemPrompt = <<<'PROMPT'
You are an expert project planner for an AI agent platform. Given a project goal, decompose it into 3-15 individual features/experiments.

For each feature, provide:
- title: Short descriptive title
- description: What this feature does and why it's needed
- priority: high/medium/low
- estimated_credits: Estimated cost in credits (1-100)
- dependencies: Array of 0-based indices of features this depends on
- suggested_agent_role: What type of agent should handle this

Output valid JSON with a "features" key containing an array of feature objects.
Ensure the dependency graph has NO cycles.
Order features so dependencies come before dependents.
PROMPT;

        $userPrompt = "Project Goal: {$goal}";
        if ($context) {
            $userPrompt .= "\n\nAdditional Context: {$context}";
        }

        $request = new AiRequestDTO(
            provider: $resolved['provider'],
            model: $resolved['model'],
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: 4096,
            teamId: $teamId,
            purpose: 'project.expand_goal',
            temperature: 0.4,
        );

        $response = $this->gateway->complete($request);

        $features = $this->parseFeatures($response->content);

        // Validate no cycles in dependency graph
        $this->validateNoCycles($features);

        // Calculate cost estimate
        $totalCredits = array_sum(array_column($features, 'estimated_credits'));

        return [
            'features' => $features,
            'cost_estimate' => $totalCredits,
            'llm_cost' => $response->usage->costCredits,
        ];
    }

    private function parseFeatures(string $content): array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*\n?/', '', $content);
            $content = preg_replace('/\n?```\s*$/', '', $content);
        }

        $parsed = json_decode($content, true, 512, JSON_UNESCAPED_UNICODE);

        if (! is_array($parsed)) {
            throw new \RuntimeException('LLM did not produce a valid JSON feature plan.');
        }

        if (isset($parsed['features']) && is_array($parsed['features'])) {
            $parsed = $parsed['features'];
        }

        return array_values($parsed);
    }

    private function validateNoCycles(array $features): void
    {
        $count = count($features);
        $visited = array_fill(0, $count, false);
        $inStack = array_fill(0, $count, false);

        for ($i = 0; $i < $count; $i++) {
            if (! $visited[$i] && $this->hasCycleDFS($i, $features, $visited, $inStack)) {
                throw new \RuntimeException('Dependency graph contains a cycle starting at feature: '.($features[$i]['title'] ?? $i));
            }
        }
    }

    private function hasCycleDFS(int $node, array $features, array &$visited, array &$inStack): bool
    {
        $visited[$node] = true;
        $inStack[$node] = true;

        $deps = $features[$node]['dependencies'] ?? [];
        foreach ($deps as $dep) {
            $dep = (int) $dep;
            if ($dep < 0 || $dep >= count($features)) {
                continue;
            }
            if (! $visited[$dep] && $this->hasCycleDFS($dep, $features, $visited, $inStack)) {
                return true;
            }
            if ($inStack[$dep]) {
                return true;
            }
        }

        $inStack[$node] = false;

        return false;
    }
}
