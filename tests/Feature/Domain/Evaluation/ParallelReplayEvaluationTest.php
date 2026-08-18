<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Enums\EvaluationCaseStatus;
use App\Domain\Evaluation\Enums\EvaluationStatus;
use App\Domain\Evaluation\Jobs\EvaluateDatasetCaseJob;
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
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ParallelReplayEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'P',
            'slug' => 'parallel-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
    }

    private function seedDataset(int $caseCount = 3): EvaluationDataset
    {
        $dataset = EvaluationDataset::create([
            'team_id' => $this->team->id,
            'name' => 'Parallel set',
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

    private function bindJudge(float $score): void
    {
        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldReceive('evaluate')->andReturn([
            'score' => $score,
            'reasoning' => 'ok',
            'cost_credits' => 2,
        ]);
        $this->app->instance(LlmJudge::class, $judge);
    }

    public function test_dispatch_parallel_queues_one_job_per_case(): void
    {
        Bus::fake();
        $dataset = $this->seedDataset(4);

        $run = app(ReplayEvaluationDatasetAction::class)->dispatchParallel(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'claude-haiku-4-5-20251001',
        );

        $this->assertSame(EvaluationStatus::Running, $run->status);
        Bus::assertBatched(fn ($batch) => count($batch->jobs) === 4
            && $batch->jobs->every(fn ($job) => $job instanceof EvaluateDatasetCaseJob));
    }

    public function test_parallel_path_matches_sequential_summary(): void
    {
        // Sequential baseline.
        $seqDataset = $this->seedDataset(5);
        $this->bindPassingGateway();
        $this->bindJudge(8.5);
        $sequential = app(ReplayEvaluationDatasetAction::class)->execute(
            teamId: $this->team->id,
            datasetId: $seqDataset->id,
            targetProvider: 'anthropic',
            targetModel: 'claude-haiku-4-5-20251001',
        );

        // Parallel run over an identical dataset, sync queue → jobs + batch finally run inline.
        $parDataset = $this->seedDataset(5);
        $parallel = app(ReplayEvaluationDatasetAction::class)->dispatchParallel(
            teamId: $this->team->id,
            datasetId: $parDataset->id,
            targetProvider: 'anthropic',
            targetModel: 'claude-haiku-4-5-20251001',
        )->refresh();

        $this->assertSame(EvaluationStatus::Completed, $parallel->status);

        $drop = ['target_provider', 'target_model'];
        $this->assertSame(
            array_diff_key($sequential->summary, array_flip($drop)),
            array_diff_key($parallel->summary, array_flip($drop)),
        );
        $this->assertEquals($sequential->aggregate_scores, $parallel->aggregate_scores);
        $this->assertSame(5, $parallel->summary['total_cases']);
        $this->assertSame(5, $parallel->summary['passed']);
        $this->assertCount(5, EvaluationRunResult::where('run_id', $parallel->id)->get());
    }

    public function test_parallel_target_failure_recorded_as_error_and_finalized(): void
    {
        $dataset = $this->seedDataset(2);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andThrow(new \RuntimeException('provider exploded'));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $run = app(ReplayEvaluationDatasetAction::class)->dispatchParallel(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'broken',
        )->refresh();

        $this->assertSame(EvaluationStatus::Completed, $run->status);
        $this->assertSame(2, $run->summary['errored']);
        $this->assertSame(0, $run->summary['passed']);
        $this->assertCount(2, EvaluationRunResult::where('run_id', $run->id)->whereNotNull('error')->get());
    }

    public function test_parallel_deferred_case_is_a_silent_win(): void
    {
        $dataset = EvaluationDataset::create([
            'team_id' => $this->team->id, 'name' => 'gate', 'case_count' => 0,
        ]);
        EvaluationCase::create([
            'dataset_id' => $dataset->id, 'team_id' => $this->team->id,
            'input' => 'active', 'expected_output' => 'e', 'status' => EvaluationCaseStatus::Active, 'metadata' => [],
        ]);
        EvaluationCase::create([
            'dataset_id' => $dataset->id, 'team_id' => $this->team->id,
            'input' => 'deferred win', 'expected_output' => 'e', 'status' => EvaluationCaseStatus::Deferred, 'metadata' => [],
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: 'a', parsedOutput: null, usage: new AiUsageDTO(1, 1, 1),
            provider: 'anthropic', model: 'm', latencyMs: 1, schemaValid: true, cached: false,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldReceive('evaluate')->andReturnUsing(fn (string $criterion, string $input) => [
            'score' => str_contains($input, 'win') ? 9.0 : 3.0, 'reasoning' => 'x', 'cost_credits' => 1,
        ]);
        $this->app->instance(LlmJudge::class, $judge);

        $run = app(ReplayEvaluationDatasetAction::class)->dispatchParallel(
            teamId: $this->team->id,
            datasetId: $dataset->id,
            targetProvider: 'anthropic',
            targetModel: 'm',
        )->refresh();

        $s = $run->summary;
        $this->assertSame(0, $s['passed']);
        $this->assertSame(1, $s['failed']);
        $this->assertSame(1, $s['deferred_count']);
        $this->assertSame(1, $s['deferred_passed']);
        $this->assertCount(1, $s['silent_wins']);
    }
}
