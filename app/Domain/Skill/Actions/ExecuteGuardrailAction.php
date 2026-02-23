<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Skill\Models\Skill;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;

class ExecuteGuardrailAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Execute a guardrail skill against input data.
     *
     * Returns a result array: {safe, risk_level, reason, blocked_content?, checked_at}
     */
    public function execute(
        Skill $guardrailSkill,
        array $input,
        string $teamId,
        string $userId,
        ?string $experimentId = null,
    ): array {
        $checkedAt = now()->toIso8601String();

        // Fast path: rule-based guardrails (no LLM required)
        $ruleResult = $this->executeRuleGuardrail($guardrailSkill, $input);
        if ($ruleResult !== null) {
            return array_merge($ruleResult, ['checked_at' => $checkedAt]);
        }

        // LLM-based guardrail
        try {
            $resolved = $this->providerResolver->resolve($guardrailSkill, null, null);
            $provider = $resolved['provider'];
            $model = $resolved['model'];

            $inputJson = json_encode($input, JSON_PRETTY_PRINT);
            $systemPrompt = $guardrailSkill->system_prompt
                ?? 'You are a safety guardrail. Analyze the input and return a JSON safety assessment.';

            $userPrompt = <<<EOT
Analyze the following input for safety issues.

Input:
{$inputJson}

Return a JSON object with exactly these fields:
{
  "safe": true|false,
  "risk_level": "low"|"medium"|"high"|"critical",
  "reason": "brief explanation",
  "blocked_content": "the specific problematic content (if any, otherwise null)"
}
EOT;

            $request = new AiRequestDTO(
                provider: $provider,
                model: $model,
                systemPrompt: $systemPrompt,
                userPrompt: $userPrompt,
                maxTokens: 512,
                userId: $userId,
                teamId: $teamId,
                experimentId: $experimentId,
                purpose: "guardrail:{$guardrailSkill->slug}",
                temperature: 0.0,
            );

            $response = $this->gateway->complete($request);
            $parsed = json_decode($response->content, true);

            if (is_array($parsed) && isset($parsed['safe'], $parsed['risk_level'], $parsed['reason'])) {
                return [
                    'safe' => (bool) $parsed['safe'],
                    'risk_level' => $parsed['risk_level'],
                    'reason' => $parsed['reason'],
                    'blocked_content' => $parsed['blocked_content'] ?? null,
                    'checked_at' => $checkedAt,
                ];
            }
        } catch (\Throwable) {
            // On guardrail execution failure, assume safe to avoid blocking legitimate work
        }

        return [
            'safe' => true,
            'risk_level' => 'low',
            'reason' => 'Guardrail check skipped (execution error)',
            'blocked_content' => null,
            'checked_at' => $checkedAt,
        ];
    }

    /**
     * Rule-based guardrail checks (no LLM, fast execution).
     * Returns null if the skill isn't a pure rule-based guardrail.
     */
    private function executeRuleGuardrail(Skill $skill, array $input): ?array
    {
        $slug = $skill->slug;
        $text = $this->extractText($input);

        if ($slug === 'guardrail-pii-detector') {
            return $this->checkPii($text);
        }

        if ($slug === 'guardrail-output-length-guard') {
            return $this->checkOutputLength($text, $skill->configuration ?? []);
        }

        if ($slug === 'guardrail-budget-guard') {
            return $this->checkBudget($input, $skill->configuration ?? []);
        }

        return null;
    }

    private function extractText(array $input): string
    {
        return implode(' ', array_filter(array_map(
            fn ($v) => is_string($v) ? $v : (is_array($v) ? json_encode($v) : null),
            $input,
        )));
    }

    private function checkPii(string $text): array
    {
        $patterns = [
            'email' => '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            'phone' => '/(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/',
            'ssn' => '/\b\d{3}-\d{2}-\d{4}\b/',
            'credit_card' => '/\b(?:4\d{12}(?:\d{3})?|5[1-5]\d{14}|3[47]\d{13}|6(?:011|5\d{2})\d{12})\b/',
        ];

        foreach ($patterns as $type => $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return [
                    'safe' => false,
                    'risk_level' => 'high',
                    'reason' => "PII detected: {$type}",
                    'blocked_content' => $matches[0],
                ];
            }
        }

        return ['safe' => true, 'risk_level' => 'low', 'reason' => 'No PII detected', 'blocked_content' => null];
    }

    private function checkOutputLength(string $text, array $config): array
    {
        $maxLength = $config['max_length'] ?? 50000;
        $length = strlen($text);

        if ($length > $maxLength) {
            return [
                'safe' => false,
                'risk_level' => 'medium',
                'reason' => "Output length ({$length} chars) exceeds maximum ({$maxLength} chars). Possible prompt injection.",
                'blocked_content' => null,
            ];
        }

        return ['safe' => true, 'risk_level' => 'low', 'reason' => "Output length {$length} chars is within limits", 'blocked_content' => null];
    }

    private function checkBudget(array $input, array $config): array
    {
        $maxCost = $config['max_cost_credits'] ?? 1000;
        $estimatedCost = $input['estimated_cost_credits'] ?? 0;

        if ($estimatedCost > $maxCost) {
            return [
                'safe' => false,
                'risk_level' => 'high',
                'reason' => "Estimated cost ({$estimatedCost} credits) exceeds budget guard limit ({$maxCost} credits).",
                'blocked_content' => null,
            ];
        }

        return ['safe' => true, 'risk_level' => 'low', 'reason' => 'Cost within budget guard limits', 'blocked_content' => null];
    }
}
