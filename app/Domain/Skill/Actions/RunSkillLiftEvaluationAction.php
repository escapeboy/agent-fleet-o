<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\SkillLiftRecommendation;
use App\Domain\Skill\Enums\SkillLiftStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Exceptions\SkillLiftEvaluationDisabledException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillLiftEvaluation;
use App\Domain\Skill\Models\SkillVersion;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Collection;

/**
 * Borrowed from ZooEval: run a skill's LLM output WITH the skill versus a baseline
 * WITHOUT it over an evaluation dataset, judge both arms with LlmJudge, and report
 * the lift (with − without), improvement rate, and a recommendation tier.
 *
 * This is the one ZooEval primitive FleetQ lacked — existing skill quality counters
 * measure production telemetry, and the benchmark loop optimizes a metric against the
 * skill's own prior version, never against the absence of the skill.
 */
class RunSkillLiftEvaluationAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly LlmJudge $judge,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * @param  array<int, string>|null  $criteria
     */
    public function execute(
        Skill $skill,
        string $teamId,
        string $userId,
        ?string $datasetId = null,
        ?array $criteria = null,
    ): SkillLiftEvaluation {
        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! ($team?->settings['skill_lift_eval_enabled'] ?? false)) {
            throw SkillLiftEvaluationDisabledException::forTeam($teamId);
        }

        $criteria ??= config('skills.lift_eval.default_criteria', ['correctness', 'relevance']);

        $currentVersion = SkillVersion::where('skill_id', $skill->id)
            ->orderByDesc('created_at')
            ->first();

        $evaluation = SkillLiftEvaluation::create([
            'team_id' => $teamId,
            'skill_id' => $skill->id,
            'skill_version_id' => $currentVersion?->id,
            'dataset_id' => $datasetId ?? $skill->eval_dataset_id,
            'status' => SkillLiftStatus::Running,
            'criteria' => $criteria,
            'started_at' => now(),
        ]);

        if ($skill->type !== SkillType::Llm) {
            return $this->fail($evaluation, 'Skill lift A/B supports LLM skills only.');
        }

        $cases = $this->resolveCases($datasetId ?? $skill->eval_dataset_id, $teamId);
        if ($cases->isEmpty()) {
            return $this->fail($evaluation, 'No evaluation dataset/cases linked to this skill.');
        }

        try {
            return $this->runComparison($skill, $team, $teamId, $userId, $criteria, $cases, $evaluation);
        } catch (\Throwable $e) {
            return $this->fail($evaluation, 'Lift evaluation failed: '.$e->getMessage());
        }
    }

    /**
     * @param  array<int, string>  $criteria
     * @param  Collection<int, EvaluationCase>  $cases
     */
    private function runComparison(
        Skill $skill,
        Team $team,
        string $teamId,
        string $userId,
        array $criteria,
        Collection $cases,
        SkillLiftEvaluation $evaluation,
    ): SkillLiftEvaluation {
        $resolved = $this->providerResolver->resolve($skill, null, $team, 'run');
        $provider = $resolved['provider'];
        $model = $resolved['model'];
        $judgeModel = config('skills.lift_eval.judge_model');
        $baselineSystem = config('skills.lift_eval.baseline_system_prompt', 'You are a helpful assistant. Answer the request directly and accurately.');
        $withSystem = $skill->system_prompt ?: $baselineSystem;
        $maxTokens = (int) ($skill->configuration['max_tokens'] ?? 1024);
        $temperature = (float) ($skill->configuration['temperature'] ?? 0.7);

        $cost = 0;
        $caseResults = [];
        $withTotal = 0.0;
        $withoutTotal = 0.0;
        $improved = 0;

        foreach ($cases as $case) {
            $input = (string) $case->input;

            $outputWith = $this->complete($provider, $model, $withSystem, $input, $maxTokens, $temperature, $teamId, $userId, $cost);
            $outputWithout = $this->complete($provider, $model, $baselineSystem, $input, $maxTokens, $temperature, $teamId, $userId, $cost);

            $withScore = $this->judgeAll($criteria, $input, $outputWith, $case, $judgeModel, $teamId, $cost);
            $withoutScore = $this->judgeAll($criteria, $input, $outputWithout, $case, $judgeModel, $teamId, $cost);

            $withTotal += $withScore;
            $withoutTotal += $withoutScore;
            if ($withScore > $withoutScore) {
                $improved++;
            }

            $caseResults[] = [
                'case_id' => $case->id,
                'with' => round($withScore, 2),
                'without' => round($withoutScore, 2),
                'delta' => round($withScore - $withoutScore, 2),
            ];
        }

        $n = $cases->count();
        $withAvg = round($withTotal / $n, 2);
        $withoutAvg = round($withoutTotal / $n, 2);
        $delta = round($withAvg - $withoutAvg, 2);

        $evaluation->update([
            'status' => SkillLiftStatus::Completed,
            'with_skill_score' => $withAvg,
            'without_skill_score' => $withoutAvg,
            'delta' => $delta,
            'improvement_rate' => round($improved / $n, 4),
            'recommendation' => SkillLiftRecommendation::fromDelta($delta),
            'case_results' => $caseResults,
            'judge_model' => $judgeModel,
            'cost_credits' => $cost,
            'completed_at' => now(),
        ]);

        return $evaluation->refresh();
    }

    private function complete(
        string $provider,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        int $maxTokens,
        float $temperature,
        string $teamId,
        string $userId,
        int &$cost,
    ): string {
        $response = $this->gateway->complete(new AiRequestDTO(
            provider: $provider,
            model: $model,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            maxTokens: $maxTokens,
            userId: $userId,
            teamId: $teamId,
            purpose: 'skill_lift_eval',
            temperature: $temperature,
        ));

        $cost += $response->usage->costCredits;

        return (string) $response->content;
    }

    /**
     * @param  array<int, string>  $criteria
     */
    private function judgeAll(
        array $criteria,
        string $input,
        string $output,
        EvaluationCase $case,
        ?string $judgeModel,
        string $teamId,
        int &$cost,
    ): float {
        $total = 0.0;
        foreach ($criteria as $criterion) {
            $result = $this->judge->evaluate(
                criterion: $criterion,
                input: $input,
                actualOutput: $output,
                expectedOutput: $case->expected_output,
                context: $case->context,
                model: $judgeModel,
                teamId: $teamId,
            );
            $total += (float) $result['score'];
            $cost += (int) ($result['cost_credits'] ?? 0);
        }

        return $criteria === [] ? 0.0 : $total / count($criteria);
    }

    /**
     * @return Collection<int, EvaluationCase>
     */
    private function resolveCases(?string $datasetId, string $teamId): Collection
    {
        if (! $datasetId) {
            return collect();
        }

        // Scope to the team — never read another tenant's dataset cases.
        $dataset = EvaluationDataset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($datasetId);

        return $dataset ? $dataset->cases()->get() : collect();
    }

    private function fail(SkillLiftEvaluation $evaluation, string $reason): SkillLiftEvaluation
    {
        $evaluation->update([
            'status' => SkillLiftStatus::Failed,
            'error' => $reason,
            'completed_at' => now(),
        ]);

        return $evaluation->refresh();
    }
}
