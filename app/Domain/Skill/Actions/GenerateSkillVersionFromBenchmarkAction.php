<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillIterationLog;
use App\Domain\Skill\Models\SkillVersion;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use RuntimeException;

/**
 * Generates an improved SkillVersion candidate for a benchmark iteration.
 *
 * Unlike GenerateImprovedSkillVersionAction (which requires human annotations),
 * this action drives improvement from benchmark iteration history:
 * the last N iteration logs are used as few-shot context for the LLM.
 */
class GenerateSkillVersionFromBenchmarkAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly UpdateSkillAction $updateSkill,
    ) {}

    /**
     * @param  Skill  $skill  The skill to improve
     * @param  SkillBenchmark  $benchmark  The running benchmark (for context + history)
     * @param  SkillVersion  $currentBest  The current best version (baseline)
     * @param  string  $userId  User who triggered the benchmark
     * @return SkillVersion The newly created candidate version
     */
    public function execute(
        Skill $skill,
        SkillBenchmark $benchmark,
        SkillVersion $currentBest,
        string $userId,
    ): SkillVersion {
        /** @var array<string, mixed> $bestConfig */
        $bestConfig = $currentBest->configuration ?? [];
        $currentTemplate = (string) ($bestConfig['prompt_template'] ?? $skill->system_prompt ?? '');

        // Fetch last 10 iteration logs for few-shot context
        $history = SkillIterationLog::where('benchmark_id', $benchmark->id)
            ->orderByDesc('iteration_number')
            ->limit(10)
            ->get();

        $metaPrompt = $this->buildMetaPrompt($currentTemplate, $benchmark, $history->all());

        $response = $this->gateway->complete(new AiRequestDTO(
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            systemPrompt: 'You are a prompt engineering expert optimising for a specific metric. Respond with ONLY the improved prompt template text — no explanation, no markdown fences.',
            userPrompt: $metaPrompt,
            maxTokens: 2048,
            userId: $userId,
            teamId: $benchmark->team_id,
            purpose: 'skill_benchmark_improvement',
        ));

        $improvedTemplate = trim($response->content);

        if (empty($improvedTemplate)) {
            throw new RuntimeException('AI returned an empty prompt template.');
        }

        if (strlen($improvedTemplate) > 10_000) {
            throw new RuntimeException('AI returned a prompt template exceeding 10,000 characters.');
        }

        $newConfiguration = array_merge($skill->configuration ?? [], [
            'prompt_template' => $improvedTemplate,
        ]);

        $updatedSkill = $this->updateSkill->execute(
            $skill,
            ['configuration' => $newConfiguration],
            sprintf('Benchmark #%s iteration %d', $benchmark->id, $benchmark->iteration_count + 1),
            $userId,
        );

        return SkillVersion::where('skill_id', $updatedSkill->id)
            ->where('version', $updatedSkill->current_version)
            ->firstOrFail();
    }

    /**
     * @param  array<SkillIterationLog>  $history
     */
    private function buildMetaPrompt(string $currentTemplate, SkillBenchmark $benchmark, array $history): string
    {
        $lines = [];
        $lines[] = '## Objective';
        $lines[] = sprintf(
            'Improve the prompt template to %s the metric "%s". The current best value is %s.',
            $benchmark->metric_direction === 'minimize' ? 'minimize' : 'maximize',
            $benchmark->metric_name,
            $benchmark->best_value !== null ? number_format($benchmark->best_value, 4) : 'not yet measured',
        );
        $lines[] = '';

        $lines[] = '## Current Best Prompt Template';
        $lines[] = $currentTemplate ?: '(empty)';
        $lines[] = '';

        if (! empty($history)) {
            $lines[] = '## Iteration History (most recent first)';
            foreach ($history as $log) {
                $metricStr = $log->metric_value !== null ? number_format($log->metric_value, 4) : 'N/A';
                $lines[] = sprintf(
                    '- Iteration %d: outcome=%s, metric=%s, complexity_delta=%s',
                    $log->iteration_number,
                    $log->outcome->value,
                    $metricStr,
                    $log->complexity_delta !== null ? (string) $log->complexity_delta : 'N/A',
                );
                if ($log->diff_summary) {
                    $lines[] = "  Change summary: {$log->diff_summary}";
                }
            }
            $lines[] = '';
        }

        $lines[] = '## Task';
        $lines[] = 'Propose an improved version of the prompt template that should yield a better metric score.';
        $lines[] = sprintf(
            'IMPORTANT: Simpler is better — a minor improvement with a simpler prompt is preferred over a large improvement with a complex prompt (complexity penalty: %s per token).',
            $benchmark->complexity_penalty,
        );
        $lines[] = 'Preserve any {{variable}} placeholders present in the original template.';
        $lines[] = 'Respond with ONLY the improved prompt template — no preamble, no explanation.';

        return implode("\n", $lines);
    }
}
