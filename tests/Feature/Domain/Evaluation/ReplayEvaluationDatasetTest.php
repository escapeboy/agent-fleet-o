<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReplayEvaluationDatasetTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'R',
            'slug' => 'replay-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
    }

    private function seedDataset(int $caseCount = 3): EvaluationDataset
    {
        $dataset = EvaluationDataset::create([
            'team_id' => $this->team->id,
            'name' => 'Test regression set',
            'description' => '',
            'case_count' => $caseCount,
        ]);
        for ($i = 0; $i < $caseCount; $i++) {
            EvaluationCase::create([
                'dataset_id' => $dataset->id,
                'team_id' => $this->team->id,
                'input' => "What is {$i}+1?",
                'expected_output' => (string) ($i + 1),
                'context' => null,
                'metadata' => [],
            ]);
        }

        return $dataset;
    }

    private function bindPassingGateway(): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: 'deterministic answer',
            parsedOutput: null,
            usage: new AiUsageDTO(10, 5, 1),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 42,
            schemaValid: true,
            cached: false,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    private function bindJudge(float $score, string $reasoning = 'ok'): void
    {
        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldReceive('evaluate')->andReturn([
            'score' => $score,
            'reasoning' => $reasoning,
            'cost_credits' => 2,
        ]);
        $this->app->instance(LlmJudge::class, $judge);
    }

    public function test_happy_path_creates_run_and_result_rows(): void
    {
        $dataset = $this->seedDataset(3);
        $this->bindPassingGateway();
        $this->bindJudge(8.5);

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'claude-haiku-4-5-20251001',
        );

        $this->assertSame(EvaluationStatus::Completed, $run->status);
        $this->assertSame(3, $run->summary['total_cases']);
        $this->assertSame(3, $run->summary['passed']);
        $this->assertSame(0, $run->summary['failed']);
        $this->assertEqualsWithDelta(100.0, $run->summary['pass_rate_pct'], 0.001);
        $this->assertEqualsWithDelta(8.5, $run->summary['overall_avg_score'], 0.01);
        $this->assertCount(3, EvaluationRunResult::where('run_id', $run->id)->get());
    }

    public function test_low_scores_flag_cases_as_failed(): void
    {
        $dataset = $this->seedDataset(4);
        $this->bindPassingGateway();
        $this->bindJudge(3.0);

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'claude-haiku-4-5-20251001',
        );

        $this->assertSame(0, $run->summary['passed']);
        $this->assertSame(4, $run->summary['failed']);
        $this->assertEqualsWithDelta(0.0, $run->summary['pass_rate_pct'], 0.001);
    }

    public function test_target_model_failure_is_recorded_as_error_result(): void
    {
        $dataset = $this->seedDataset(2);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andThrow(new \RuntimeException('provider exploded'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldNotReceive('evaluate');
        $this->app->instance(LlmJudge::class, $judge);

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'broken',
        );

        $this->assertSame(2, $run->summary['errored']);
        $this->assertSame(0, $run->summary['passed']);
        $errored = EvaluationRunResult::where('run_id', $run->id)->whereNotNull('error')->get();
        $this->assertCount(2, $errored);
        $this->assertStringContainsString('provider exploded', $errored->first()->error);
    }

    public function test_judge_failure_falls_through_with_zero_score(): void
    {
        $dataset = $this->seedDataset(1);
        $this->bindPassingGateway();

        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldReceive('evaluate')->andThrow(new \RuntimeException('judge timeout'));
        $this->app->instance(LlmJudge::class, $judge);

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
        );

        // Target ran successfully so not errored, but judge failure → 0 score → failed bucket.
        $this->assertSame(0, $run->summary['errored']);
        $this->assertSame(1, $run->summary['failed']);
    }

    public function test_missing_dataset_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: (string) \Illuminate\Support\Str::uuid(),
            targetProvider: 'anthropic',
            targetModel: 'm',
        );
    }

    public function test_empty_dataset_throws(): void
    {
        $dataset = EvaluationDataset::create([
            'team_id' => $this->team->id,
            'name' => 'empty',
            'case_count' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
        );
    }

    public function test_invalid_criteria_filtered_out(): void
    {
        $dataset = $this->seedDataset(1);
        $this->bindPassingGateway();
        $this->bindJudge(9.0);

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
            criteria: ['correctness', 'frobnicator', 'relevance'],
        );

        // Bogus criterion dropped; only valid ones used.
        $this->assertEqualsCanonicalizing(['correctness', 'relevance'], $run->criteria);
    }

    public function test_empty_criteria_after_filter_throws(): void
    {
        $dataset = $this->seedDataset(1);
        $this->expectException(\InvalidArgumentException::class);
        app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
            criteria: ['only_invalid'],
        );
    }

    public function test_max_cases_caps_replay(): void
    {
        $dataset = $this->seedDataset(10);
        $this->bindPassingGateway();
        $this->bindJudge(8.0);

        $run = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
            maxCases: 3,
        );

        $this->assertSame(3, $run->summary['total_cases']);
    }

    public function test_cross_team_dataset_access_rejected(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'other',
            'slug' => 'other-'.uniqid(),
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $otherDataset = EvaluationDataset::create([
            'team_id' => $otherTeam->id,
            'name' => 'other-set',
            'case_count' => 0,
        ]);

        $this->expectException(\RuntimeException::class);
        app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id, // this team, but dataset belongs to other team
            datasetId: $otherDataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
        );
    }
}
