<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\RunSkillLiftEvaluationAction;
use App\Domain\Skill\Enums\SkillLiftRecommendation;
use App\Domain\Skill\Enums\SkillLiftStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Exceptions\SkillLiftEvaluationDisabledException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillLiftEvaluation;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RunSkillLiftEvaluationActionTest extends TestCase
{
    use RefreshDatabase;

    private function bindGateway(float $withScore, float $withoutScore): void
    {
        $gateway = new class($withScore, $withoutScore) implements AiGatewayInterface
        {
            public function __construct(private float $w, private float $wo) {}

            public function complete(AiRequestDTO $request): AiResponseDTO
            {
                if (str_contains($request->systemPrompt, 'evaluation judge')) {
                    $score = str_contains($request->userPrompt, 'WITHSKILL') ? $this->w : $this->wo;
                    $content = json_encode(['score' => $score, 'reasoning' => 'test']);
                } elseif (str_contains($request->systemPrompt, 'SKILLPROMPT')) {
                    $content = 'WITHSKILL-out';
                } else {
                    $content = 'baseline-out';
                }

                return new AiResponseDTO(
                    content: (string) $content,
                    parsedOutput: null,
                    usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 1),
                    provider: 'anthropic',
                    model: 'claude-sonnet-4-5',
                    latencyMs: 1,
                );
            }

            public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
            {
                return $this->complete($request);
            }

            public function estimateCost(AiRequestDTO $request): int
            {
                return 1;
            }
        };

        $this->app->instance(AiGatewayInterface::class, $gateway);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']);
        $this->app->instance(ProviderResolver::class, $resolver);
    }

    private function team(bool $enabled = true): Team
    {
        return Team::factory()->create([
            'settings' => $enabled ? ['skill_lift_eval_enabled' => true] : [],
        ]);
    }

    private function llmSkill(Team $team, ?string $datasetId): Skill
    {
        return Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => 'SKILLPROMPT: answer precisely.',
            'eval_dataset_id' => $datasetId,
        ]);
    }

    private function datasetWithCases(Team $team): EvaluationDataset
    {
        $dataset = EvaluationDataset::create(['team_id' => $team->id, 'name' => 'DS']);
        EvaluationCase::create(['dataset_id' => $dataset->id, 'team_id' => $team->id, 'input' => 'Q1', 'expected_output' => 'A1']);
        EvaluationCase::create(['dataset_id' => $dataset->id, 'team_id' => $team->id, 'input' => 'Q2', 'expected_output' => 'A2']);

        return $dataset;
    }

    private function runEval(Skill $skill, Team $team): SkillLiftEvaluation
    {
        return app(RunSkillLiftEvaluationAction::class)->execute(
            skill: $skill,
            teamId: $team->id,
            userId: 'user-1',
            criteria: ['correctness', 'relevance'],
        );
    }

    public function test_skill_with_positive_lift_is_highly_recommended(): void
    {
        $this->bindGateway(withScore: 9.0, withoutScore: 4.0);
        $team = $this->team();
        $skill = $this->llmSkill($team, $this->datasetWithCases($team)->id);

        $eval = $this->runEval($skill, $team);

        $this->assertSame(SkillLiftStatus::Completed, $eval->status);
        $this->assertEqualsWithDelta(9.0, (float) $eval->with_skill_score, 0.01);
        $this->assertEqualsWithDelta(4.0, (float) $eval->without_skill_score, 0.01);
        $this->assertEqualsWithDelta(5.0, (float) $eval->delta, 0.01);
        $this->assertEqualsWithDelta(1.0, (float) $eval->improvement_rate, 0.001);
        $this->assertSame(SkillLiftRecommendation::HighlyRecommended, $eval->recommendation);
        $this->assertCount(2, $eval->case_results);
        $this->assertGreaterThan(0, $eval->cost_credits);
    }

    public function test_skill_with_no_lift_is_marginal(): void
    {
        $this->bindGateway(withScore: 5.0, withoutScore: 5.0);
        $team = $this->team();
        $skill = $this->llmSkill($team, $this->datasetWithCases($team)->id);

        $eval = $this->runEval($skill, $team);

        $this->assertEqualsWithDelta(0.0, (float) $eval->delta, 0.01);
        $this->assertSame(SkillLiftRecommendation::Marginal, $eval->recommendation);
    }

    public function test_skill_that_hurts_is_harmful(): void
    {
        $this->bindGateway(withScore: 3.0, withoutScore: 7.0);
        $team = $this->team();
        $skill = $this->llmSkill($team, $this->datasetWithCases($team)->id);

        $eval = $this->runEval($skill, $team);

        $this->assertLessThan(0, (float) $eval->delta);
        $this->assertSame(SkillLiftRecommendation::Harmful, $eval->recommendation);
        $this->assertEqualsWithDelta(0.0, (float) $eval->improvement_rate, 0.001);
    }

    public function test_disabled_flag_throws(): void
    {
        $this->bindGateway(9.0, 4.0);
        $team = $this->team(enabled: false);
        $skill = $this->llmSkill($team, $this->datasetWithCases($team)->id);

        $this->expectException(SkillLiftEvaluationDisabledException::class);
        $this->runEval($skill, $team);
    }

    public function test_non_llm_skill_fails_gracefully(): void
    {
        $this->bindGateway(9.0, 4.0);
        $team = $this->team();
        $dataset = $this->datasetWithCases($team);
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Connector,
            'eval_dataset_id' => $dataset->id,
        ]);

        $eval = $this->runEval($skill, $team);

        $this->assertSame(SkillLiftStatus::Failed, $eval->status);
        $this->assertStringContainsString('LLM skills only', (string) $eval->error);
    }

    public function test_missing_dataset_fails_gracefully(): void
    {
        $this->bindGateway(9.0, 4.0);
        $team = $this->team();
        $skill = $this->llmSkill($team, null);

        $eval = $this->runEval($skill, $team);

        $this->assertSame(SkillLiftStatus::Failed, $eval->status);
        $this->assertStringContainsString('dataset', (string) $eval->error);
    }
}
