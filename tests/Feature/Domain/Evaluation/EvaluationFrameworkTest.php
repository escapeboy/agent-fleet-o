<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Actions\CreateEvaluationDatasetAction;
use App\Domain\Evaluation\Actions\RunStructuredEvaluationAction;
use App\Domain\Evaluation\Enums\EvaluationCriterion;
use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationScore;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EvaluationFrameworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_dataset_with_cases(): void
    {
        $team = Team::factory()->create();

        $dataset = app(CreateEvaluationDatasetAction::class)->execute(
            teamId: $team->id,
            name: 'Test Dataset',
            description: 'A test dataset',
            cases: [
                ['input' => 'What is 2+2?', 'expected_output' => '4', 'context' => 'Math'],
                ['input' => 'What is the capital of France?', 'expected_output' => 'Paris'],
            ],
        );

        $this->assertSame('Test Dataset', $dataset->name);
        $this->assertSame(2, $dataset->case_count);
        $this->assertCount(2, $dataset->cases);
        $this->assertSame($team->id, $dataset->cases->first()->team_id);
    }

    public function test_evaluation_dataset_belongs_to_team(): void
    {
        $team = Team::factory()->create();
        $dataset = EvaluationDataset::create([
            'team_id' => $team->id,
            'name' => 'Test',
        ]);

        $this->assertSame($team->id, $dataset->team_id);
    }

    public function test_run_structured_evaluation_with_mocked_gateway(): void
    {
        $team = Team::factory()->create();

        // Mock the AI Gateway to return a valid judge response
        $mockGateway = Mockery::mock(AiGatewayInterface::class);
        $mockGateway->shouldReceive('complete')
            ->andReturn(new AiResponseDTO(
                content: json_encode(['score' => 8.5, 'reasoning' => 'Good output']),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 5),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 100,
            ));

        $this->app->instance(AiGatewayInterface::class, $mockGateway);

        $action = app(RunStructuredEvaluationAction::class);
        $run = $action->execute(
            teamId: $team->id,
            criteria: ['faithfulness', 'relevance'],
            input: 'What is machine learning?',
            actualOutput: 'Machine learning is a subset of AI that enables systems to learn from data.',
            context: 'Machine learning is a field of AI focused on algorithms that learn from data.',
        );

        $this->assertSame(EvaluationStatus::Completed, $run->status);
        $this->assertArrayHasKey('faithfulness', $run->aggregate_scores);
        $this->assertArrayHasKey('relevance', $run->aggregate_scores);
        $this->assertSame(8.5, $run->aggregate_scores['faithfulness']);
        $this->assertSame(10, $run->total_cost_credits); // 5 per criterion × 2
        $this->assertCount(2, $run->scores);
    }

    public function test_evaluation_rejects_invalid_criteria(): void
    {
        $team = Team::factory()->create();

        // Mock gateway to avoid container resolution issues
        $mockGateway = Mockery::mock(AiGatewayInterface::class);
        $this->app->instance(AiGatewayInterface::class, $mockGateway);

        $this->expectException(\InvalidArgumentException::class);

        app(RunStructuredEvaluationAction::class)->execute(
            teamId: $team->id,
            criteria: ['nonexistent_criterion'],
            input: 'test',
            actualOutput: 'test',
        );
    }

    public function test_llm_judge_rejects_out_of_range_score(): void
    {
        $mockGateway = Mockery::mock(AiGatewayInterface::class);
        $mockGateway->shouldReceive('complete')
            ->andReturn(new AiResponseDTO(
                content: json_encode(['score' => 15, 'reasoning' => 'Hacked']),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 5),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 100,
            ));

        $judge = new LlmJudge($mockGateway);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid score');

        $judge->evaluate(
            criterion: 'faithfulness',
            input: 'test',
            actualOutput: 'test',
        );
    }

    public function test_llm_judge_rejects_negative_score(): void
    {
        $mockGateway = Mockery::mock(AiGatewayInterface::class);
        $mockGateway->shouldReceive('complete')
            ->andReturn(new AiResponseDTO(
                content: json_encode(['score' => -1, 'reasoning' => 'Negative']),
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 5),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 100,
            ));

        $judge = new LlmJudge($mockGateway);

        $this->expectException(\RuntimeException::class);

        $judge->evaluate(
            criterion: 'relevance',
            input: 'test',
            actualOutput: 'test',
        );
    }

    public function test_evaluation_score_model_relationships(): void
    {
        $team = Team::factory()->create();

        $dataset = EvaluationDataset::create([
            'team_id' => $team->id,
            'name' => 'Test',
        ]);

        $case = EvaluationCase::create([
            'dataset_id' => $dataset->id,
            'team_id' => $team->id,
            'input' => 'test input',
        ]);

        $run = EvaluationRun::create([
            'team_id' => $team->id,
            'dataset_id' => $dataset->id,
            'status' => 'completed',
            'criteria' => ['faithfulness'],
        ]);

        $score = EvaluationScore::create([
            'run_id' => $run->id,
            'case_id' => $case->id,
            'criterion' => 'faithfulness',
            'score' => 7.50,
            'reasoning' => 'Good',
            'created_at' => now(),
        ]);

        $this->assertTrue($score->run->is($run));
        $this->assertTrue($score->evaluationCase->is($case));
        $this->assertTrue($run->dataset->is($dataset));
        $this->assertCount(1, $run->scores);
    }

    public function test_evaluation_criterion_enum(): void
    {
        $this->assertSame('faithfulness', EvaluationCriterion::Faithfulness->value);
        $this->assertSame('Faithfulness', EvaluationCriterion::Faithfulness->label());
    }
}
