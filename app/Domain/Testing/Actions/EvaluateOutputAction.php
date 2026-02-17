<?php

namespace App\Domain\Testing\Actions;

class EvaluateOutputAction
{
    /**
     * Evaluate experiment output against assertion rules.
     * Uses a simple rule-based evaluation for now (LLM-as-judge can be added later).
     *
     * @return array{passed: bool, score: float, details: array, feedback: ?array}
     */
    public function execute(array $output, array $assertionRules, float $qualityThreshold = 0.7): array
    {
        $results = [];
        $totalScore = 0;
        $ruleCount = 0;

        foreach ($assertionRules as $rule) {
            $result = $this->evaluateRule($rule, $output);
            $results[] = $result;
            $totalScore += $result['score'];
            $ruleCount++;
        }

        // If no rules, check basic sanity (stages completed, no errors)
        if ($ruleCount === 0) {
            $sanity = $this->basicSanityCheck($output);
            $results[] = $sanity;
            $totalScore += $sanity['score'];
            $ruleCount = 1;
        }

        $avgScore = $totalScore / $ruleCount;
        $passed = $avgScore >= $qualityThreshold;

        return [
            'passed' => $passed,
            'score' => round($avgScore, 2),
            'details' => $results,
            'feedback' => $passed ? null : [
                'summary' => 'Quality score '.$avgScore.' below threshold '.$qualityThreshold,
                'failed_rules' => collect($results)->where('score', '<', $qualityThreshold)->values()->toArray(),
            ],
        ];
    }

    private function evaluateRule(array $rule, array $output): array
    {
        $type = $rule['type'] ?? 'contains';
        $target = $rule['target'] ?? '';
        $field = $rule['field'] ?? 'stages';

        $outputText = json_encode(data_get($output, $field, ''));

        return match ($type) {
            'contains' => [
                'rule' => $rule,
                'passed' => str_contains($outputText, $target),
                'score' => str_contains($outputText, $target) ? 1.0 : 0.0,
            ],
            'not_contains' => [
                'rule' => $rule,
                'passed' => ! str_contains($outputText, $target),
                'score' => ! str_contains($outputText, $target) ? 1.0 : 0.0,
            ],
            'regex' => [
                'rule' => $rule,
                'passed' => (bool) preg_match($target, $outputText),
                'score' => preg_match($target, $outputText) ? 1.0 : 0.0,
            ],
            'min_stages' => [
                'rule' => $rule,
                'passed' => count($output['stages'] ?? []) >= (int) $target,
                'score' => count($output['stages'] ?? []) >= (int) $target ? 1.0 : 0.0,
            ],
            default => [
                'rule' => $rule,
                'passed' => false,
                'score' => 0.0,
            ],
        };
    }

    private function basicSanityCheck(array $output): array
    {
        $stages = $output['stages'] ?? [];
        $hasStages = count($stages) > 0;
        $hasErrors = collect($stages)->contains(fn ($s) => str_contains($s['status'] ?? '', 'failed'));

        $score = 0.0;
        if ($hasStages) {
            $score += 0.5;
        }
        if (! $hasErrors) {
            $score += 0.5;
        }

        return [
            'rule' => ['type' => 'sanity_check'],
            'passed' => $score >= 0.5,
            'score' => $score,
        ];
    }
}
