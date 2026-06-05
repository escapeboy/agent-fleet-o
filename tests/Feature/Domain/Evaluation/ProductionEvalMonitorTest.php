<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Actions\RunProductionEvalMonitorAction;
use App\Domain\Evaluation\Models\EvaluationCase;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationMonitorSnapshot;
use App\Domain\Evaluation\Services\LlmJudge;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductionEvalMonitorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create(['name' => 'PM', 'slug' => 'pm-'.uniqid(), 'owner_id' => $user->id, 'settings' => []]);
        config(['evaluation.auto_eval.dataset_name' => 'Production Regressions']);
    }

    private function seedMonitorDataset(int $cases = 2): EvaluationDataset
    {
        $dataset = EvaluationDataset::create([
            'team_id' => $this->team->id, 'name' => 'Production Regressions', 'case_count' => $cases,
        ]);
        for ($i = 0; $i < $cases; $i++) {
            EvaluationCase::create([
                'dataset_id' => $dataset->id, 'team_id' => $this->team->id,
                'input' => "q{$i}", 'expected_output' => 'e', 'metadata' => [],
            ]);
        }

        return $dataset;
    }

    private function fakeAi(float $score): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: 'answer', parsedOutput: null, usage: new AiUsageDTO(10, 5, 1),
            provider: 'anthropic', model: 'm', latencyMs: 10, schemaValid: true, cached: false,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $judge = Mockery::mock(LlmJudge::class);
        $judge->shouldReceive('evaluate')->andReturn(['score' => $score, 'reasoning' => 'x', 'cost_credits' => 1]);
        $this->app->instance(LlmJudge::class, $judge);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['provider' => 'anthropic', 'model' => 'm']);
        $this->app->instance(ProviderResolver::class, $resolver);
    }

    public function test_disabled_flag_writes_no_snapshot(): void
    {
        config(['evaluation.production_monitor.enabled' => false]);
        $this->seedMonitorDataset();

        $this->artisan('evaluation:monitor-production')->assertSuccessful();
        $this->assertSame(0, EvaluationMonitorSnapshot::count());
    }

    public function test_empty_dataset_is_skipped(): void
    {
        config(['evaluation.production_monitor.enabled' => true]);
        EvaluationDataset::create(['team_id' => $this->team->id, 'name' => 'Production Regressions', 'case_count' => 0]);

        $snapshot = app(RunProductionEvalMonitorAction::class)->execute($this->team);
        $this->assertNull($snapshot);
    }

    public function test_writes_snapshot_for_team_with_cases(): void
    {
        config([
            'evaluation.production_monitor.enabled' => true,
            'evaluation.production_monitor.sample_size' => 5,
        ]);
        $this->seedMonitorDataset(2);
        $this->fakeAi(8.0);

        $snapshot = app(RunProductionEvalMonitorAction::class)->execute($this->team);

        $this->assertNotNull($snapshot);
        $this->assertSame(2, $snapshot->sampled_count);
        $this->assertEqualsWithDelta(8.0, $snapshot->avg_score, 0.01);
        $this->assertSame(1, EvaluationMonitorSnapshot::where('team_id', $this->team->id)->count());
    }
}
