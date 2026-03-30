<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\CancelSkillBenchmarkAction;
use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Actions\MeasureSkillMetricAction;
use App\Domain\Skill\Actions\StartSkillBenchmarkAction;
use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Enums\IterationOutcome;
use App\Domain\Skill\Exceptions\BenchmarkAlreadyRunningException;
use App\Domain\Skill\Jobs\SkillImprovementIterationJob;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\SkillIterationLog;
use App\Domain\Skill\Models\SkillVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SkillBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    private Skill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-benchmark-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->skill = Skill::create([
            'team_id' => $this->team->id,
            'name' => 'Test Skill',
            'slug' => 'test-skill-'.uniqid(),
            'type' => 'llm',
            'status' => 'active',
            'configuration' => ['prompt_template' => 'Hello {{input}}'],
            'applied_count' => 0,
            'completed_count' => 0,
            'effective_count' => 0,
            'fallback_count' => 0,
        ]);

        // Create a baseline version for the skill
        SkillVersion::create([
            'skill_id' => $this->skill->id,
            'version' => 1,
            'configuration' => ['prompt_template' => 'Hello {{input}}'],
            'changelog' => 'Initial version',
            'created_by' => $this->user->id,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // StartSkillBenchmarkAction
    // -------------------------------------------------------------------------

    public function test_start_benchmark_creates_record_and_dispatches_job(): void
    {
        Queue::fake();

        $executeSkill = $this->mockExecuteSkillWithLatency(100);
        $measureMetric = new MeasureSkillMetricAction;

        $action = new StartSkillBenchmarkAction($executeSkill, $measureMetric);

        $benchmark = $action->execute(
            skill: $this->skill,
            userId: $this->user->id,
            metricName: 'latency_ms',
            testInputs: [['text' => 'test']],
            timeBudgetSeconds: 3600,
            maxIterations: 10,
        );

        Queue::assertPushed(SkillImprovementIterationJob::class);
        $this->assertInstanceOf(SkillBenchmark::class, $benchmark);
        $this->assertEquals(BenchmarkStatus::Running, $benchmark->status);
        $this->assertEquals('latency_ms', $benchmark->metric_name);
        $this->assertEquals($this->skill->id, $benchmark->skill_id);
        $this->assertEquals($this->team->id, $benchmark->team_id);
        $this->assertNotNull($benchmark->started_at);
    }

    public function test_start_benchmark_blocks_concurrent_benchmarks(): void
    {
        SkillBenchmark::create([
            'skill_id' => $this->skill->id,
            'team_id' => $this->team->id,
            'metric_name' => 'latency_ms',
            'metric_direction' => 'maximize',
            'test_inputs' => [],
            'status' => BenchmarkStatus::Running,
            'started_at' => now(),
        ]);

        $this->expectException(BenchmarkAlreadyRunningException::class);

        $executeSkill = Mockery::mock(ExecuteSkillAction::class);
        $measureMetric = new MeasureSkillMetricAction;
        $action = new StartSkillBenchmarkAction($executeSkill, $measureMetric);

        $action->execute(
            skill: $this->skill,
            userId: $this->user->id,
            metricName: 'latency_ms',
            testInputs: [['text' => 'test']],
        );
    }

    // -------------------------------------------------------------------------
    // CancelSkillBenchmarkAction
    // -------------------------------------------------------------------------

    public function test_cancel_benchmark_sets_cancelled_status(): void
    {
        $benchmark = SkillBenchmark::create([
            'skill_id' => $this->skill->id,
            'team_id' => $this->team->id,
            'metric_name' => 'latency_ms',
            'metric_direction' => 'maximize',
            'test_inputs' => [],
            'status' => BenchmarkStatus::Running,
            'started_at' => now(),
        ]);

        $result = app(CancelSkillBenchmarkAction::class)->execute($benchmark);

        $this->assertEquals(BenchmarkStatus::Cancelled, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function test_cancel_terminal_benchmark_throws(): void
    {
        $benchmark = SkillBenchmark::create([
            'skill_id' => $this->skill->id,
            'team_id' => $this->team->id,
            'metric_name' => 'latency_ms',
            'metric_direction' => 'maximize',
            'test_inputs' => [],
            'status' => BenchmarkStatus::Completed,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        app(CancelSkillBenchmarkAction::class)->execute($benchmark);
    }

    // -------------------------------------------------------------------------
    // SkillBenchmark model helpers
    // -------------------------------------------------------------------------

    public function test_benchmark_improvement_percent_calculation(): void
    {
        $benchmark = new SkillBenchmark;
        $benchmark->baseline_value = 100.0;
        $benchmark->best_value = 110.0;

        $this->assertEquals(10.0, $benchmark->improvementPercent());
    }

    public function test_benchmark_should_continue_false_when_cancelled(): void
    {
        $benchmark = new SkillBenchmark;
        $benchmark->status = BenchmarkStatus::Cancelled;
        $benchmark->iteration_count = 0;
        $benchmark->max_iterations = 50;
        $benchmark->time_budget_seconds = 3600;
        $benchmark->started_at = now();

        $this->assertFalse($benchmark->shouldContinue());
    }

    public function test_benchmark_should_continue_false_when_iterations_exhausted(): void
    {
        $benchmark = new SkillBenchmark;
        $benchmark->status = BenchmarkStatus::Running;
        $benchmark->iteration_count = 50;
        $benchmark->max_iterations = 50;
        $benchmark->time_budget_seconds = 3600;
        $benchmark->started_at = now();

        $this->assertFalse($benchmark->shouldContinue());
    }

    // -------------------------------------------------------------------------
    // API endpoints
    // -------------------------------------------------------------------------

    public function test_api_list_benchmarks_returns_empty_initially(): void
    {
        $this->actingAs($this->user)
            ->getJson("/api/v1/skills/{$this->skill->id}/benchmarks")
            ->assertOk();
    }

    public function test_api_cancel_benchmark(): void
    {
        $benchmark = SkillBenchmark::create([
            'skill_id' => $this->skill->id,
            'team_id' => $this->team->id,
            'metric_name' => 'latency_ms',
            'metric_direction' => 'maximize',
            'test_inputs' => [],
            'status' => BenchmarkStatus::Running,
            'started_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/skills/{$this->skill->id}/benchmarks/{$benchmark->id}")
            ->assertOk()
            ->assertJsonFragment(['status' => 'cancelled']);
    }

    public function test_api_show_benchmark_includes_stats(): void
    {
        $benchmark = SkillBenchmark::create([
            'skill_id' => $this->skill->id,
            'team_id' => $this->team->id,
            'metric_name' => 'latency_ms',
            'metric_direction' => 'maximize',
            'test_inputs' => [],
            'status' => BenchmarkStatus::Completed,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'iteration_count' => 3,
        ]);

        SkillIterationLog::create([
            'benchmark_id' => $benchmark->id,
            'skill_id' => $this->skill->id,
            'team_id' => $this->team->id,
            'iteration_number' => 1,
            'baseline_at_iteration' => 100.0,
            'outcome' => IterationOutcome::Keep,
            'metric_value' => 110.0,
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/v1/skills/{$this->skill->id}/benchmarks/{$benchmark->id}")
            ->assertOk()
            ->assertJsonFragment(['metric_name' => 'latency_ms'])
            ->assertJsonPath('stats.keep', 1);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockExecuteSkillWithLatency(int $latencyMs): ExecuteSkillAction
    {
        $execution = new SkillExecution;
        $execution->id = 'test-exec-'.uniqid();
        $execution->output = 'result';
        $execution->duration_ms = $latencyMs;

        $mock = Mockery::mock(ExecuteSkillAction::class);
        $mock->shouldReceive('execute')->andReturn(['execution' => $execution, 'output' => 'result']);

        return $mock;
    }
}
