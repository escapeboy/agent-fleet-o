<?php

namespace App\Domain\Workflow\Services;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class WorkflowSuggestionEngine
{
    /**
     * Analyze a completed/evaluating workflow experiment and generate optimization suggestions.
     *
     * @return array<int, array{type: string, step_id: string|null, node_id: string|null, current_value: string, suggested_value: string, reason: string, expected_improvement: string}>
     */
    public function analyze(Experiment $experiment): array
    {
        $steps = PlaybookStep::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->whereNotNull('completed_at')
            ->with(['skill', 'agent'])
            ->orderBy('order')
            ->get();

        if ($steps->isEmpty()) {
            return [];
        }

        // Compute team average cost for comparison
        $teamAvgCost = PlaybookStep::withoutGlobalScopes()
            ->whereHas('experiment', fn ($q) => $q->where('team_id', $experiment->team_id))
            ->whereNotNull('cost_credits')
            ->where('cost_credits', '>', 0)
            ->avg('cost_credits') ?? 0;

        $stepData = $steps->map(function (PlaybookStep $step) use ($teamAvgCost) {
            return [
                'id' => $step->id,
                'order' => $step->order,
                'execution_mode' => $step->execution_mode?->value ?? 'sequential',
                'group_id' => $step->group_id,
                'status' => $step->status,
                'skill_name' => $step->skill?->name,
                'skill_type' => $step->skill?->type?->value,
                'agent_name' => $step->agent?->name,
                'agent_model' => $step->agent?->model,
                'cost_credits' => (int) ($step->cost_credits ?? 0),
                'duration_ms' => (int) ($step->duration_ms ?? 0),
                'team_avg_cost' => (int) round($teamAvgCost),
                'cost_vs_avg_ratio' => $teamAvgCost > 0 ? round($step->cost_credits / $teamAvgCost, 2) : null,
                'has_guardrail_block' => isset($step->guardrail_result['safe']) && $step->guardrail_result['safe'] === false,
                'output_keys' => is_array($step->output) ? array_keys($step->output) : [],
            ];
        })->toArray();

        $workflowContext = [
            'experiment_title' => $experiment->title,
            'experiment_goal' => $experiment->goal,
            'total_steps' => $steps->count(),
            'total_cost_credits' => $steps->sum('cost_credits'),
            'total_duration_ms' => $steps->sum('duration_ms'),
            'steps' => $stepData,
        ];

        try {
            $suggestions = $this->callLlm($workflowContext);

            Log::info('WorkflowSuggestionEngine: generated suggestions', [
                'experiment_id' => $experiment->id,
                'suggestion_count' => count($suggestions),
            ]);

            return $suggestions;
        } catch (\Throwable $e) {
            Log::error('WorkflowSuggestionEngine: LLM call failed', [
                'experiment_id' => $experiment->id,
                'error' => $e->getMessage(),
            ]);

            // Fallback: rule-based suggestions without LLM
            return $this->ruleBased($steps, $teamAvgCost);
        }
    }

    private function callLlm(array $context): array
    {
        $systemPrompt = <<<'SYSTEM'
You are a workflow optimization expert analyzing AI agent execution data.

Given a workflow's step-by-step execution metrics, identify concrete optimization opportunities.

Return a JSON array of suggestions (max 5). Each suggestion must have:
- type: "parallelize" | "replace_skill" | "switch_model"
- step_id: the step ID from the data (or null if applies to multiple steps)
- current_value: what is currently configured
- suggested_value: what to change it to
- reason: brief explanation of the inefficiency (1-2 sentences)
- expected_improvement: quantified estimate (e.g. "Save ~40% cost", "2x faster", "Reduce errors by 30%")

Rules:
- "parallelize": suggest for sequential steps that have no output dependency between them (different output keys)
- "replace_skill": suggest when a step has many guardrail blocks or repeated failures
- "switch_model": suggest when cost_vs_avg_ratio > 2.0 (overspending), recommend a cheaper model
- Only include suggestions with clear evidence from the data
- Respond with valid JSON array only, no markdown
SYSTEM;

        $prompt = 'Analyze this workflow and return optimization suggestions as a JSON array:' .
            "\n\n" . json_encode($context, JSON_PRETTY_PRINT);

        $response = Prism::text()
            ->using('anthropic', 'claude-sonnet-4-20250514')
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($prompt)
            ->withMaxTokens(2048)
            ->usingTemperature(0.2)
            ->withClientOptions(['timeout' => 30])
            ->asText();

        $text = trim($response->text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-z]*\n?/', '', $text);
            $text = preg_replace('/\n?```$/', '', $text);
        }

        $decoded = json_decode(trim($text), true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, fn ($s) => isset($s['type'], $s['reason']));
    }

    /**
     * Fallback rule-based suggestions when LLM is unavailable.
     */
    private function ruleBased($steps, float $teamAvgCost): array
    {
        $suggestions = [];

        // Check for high-cost steps
        foreach ($steps as $step) {
            if ($teamAvgCost > 0 && $step->cost_credits > ($teamAvgCost * 2)) {
                $suggestions[] = [
                    'type' => 'switch_model',
                    'step_id' => $step->id,
                    'node_id' => $step->workflow_node_id,
                    'current_value' => $step->agent?->model ?? 'unknown',
                    'suggested_value' => 'claude-haiku-4-5-20251001',
                    'reason' => "Step costs {$step->cost_credits} credits — " . round($step->cost_credits / $teamAvgCost, 1) . '× the team average.',
                    'expected_improvement' => 'Estimated 60–80% cost reduction by switching to a lighter model.',
                ];
            }
        }

        // Check for sequential steps with no shared outputs that could run in parallel
        $sequential = $steps->where('execution_mode.value', 'sequential')->values();
        for ($i = 0; $i < $sequential->count() - 1; $i++) {
            $a = $sequential[$i];
            $b = $sequential[$i + 1];
            $aKeys = is_array($a->output) ? array_keys($a->output) : [];
            $bKeys = is_array($b->output) ? array_keys($b->output) : [];
            if (empty(array_intersect($aKeys, $bKeys)) && $a->group_id === null && $b->group_id === null) {
                $suggestions[] = [
                    'type' => 'parallelize',
                    'step_id' => null,
                    'node_id' => null,
                    'current_value' => 'sequential',
                    'suggested_value' => 'parallel',
                    'reason' => "Steps \"{$a->skill?->name}\" and \"{$b->skill?->name}\" have no output dependencies and could run simultaneously.",
                    'expected_improvement' => 'Reduce total duration by ~' . round(min($a->duration_ms, $b->duration_ms) / 1000, 1) . 's.',
                ];
                break; // One parallelization suggestion is enough
            }
        }

        return array_slice($suggestions, 0, 5);
    }
}
