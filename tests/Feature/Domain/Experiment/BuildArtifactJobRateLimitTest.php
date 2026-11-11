<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentTask;
use App\Domain\Experiment\Pipeline\BuildArtifactJob;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\RateLimitExceededException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Tests\TestCase;

/**
 * A provider rate limit is transient: the build task must be requeued and the
 * job released after the provider's retry-after, not marked Failed.
 */
class BuildArtifactJobRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T', 'slug' => 't-'.Str::random(6),
            'owner_id' => $user->id, 'settings' => [],
        ]);
    }

    private function bindGatewayThrowing(\Throwable $e): void
    {
        $gw = Mockery::mock(AiGatewayInterface::class);
        $gw->shouldReceive('complete')->andThrow($e);
        $this->app->instance(AiGatewayInterface::class, $gw);
    }

    private function makeTask(): ExperimentTask
    {
        $exp = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'title' => 'Build things',
            'thesis' => 'It works',
            'constraints' => ['llm' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']],
        ]);

        return ExperimentTask::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $exp->id,
            'stage' => 'building',
            'name' => 'Landing page',
            'type' => 'code',
            'status' => ExperimentTaskStatus::Pending,
            'input_data' => [
                'plan' => [],
                'artifact_spec' => ['type' => 'code', 'name' => 'Landing page', 'description' => 'A page'],
            ],
        ]);
    }

    private function runJob(ExperimentTask $task): BuildArtifactJob
    {
        $job = (new BuildArtifactJob($task->experiment_id, $task->id, $task->team_id))->withFakeQueueInteractions();
        $job->handle();

        return $job;
    }

    public function test_rate_limit_releases_job_with_provider_retry_after_and_requeues_task(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 6));
        $task = $this->makeTask();

        $job = $this->runJob($task);

        $job->assertReleased();
        $this->assertGreaterThanOrEqual(7, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(11, $job->job->releaseDelay);

        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Pending, $task->status);
        $this->assertNull($task->started_at);
        $this->assertNull($task->completed_at);
        $this->assertStringContainsString('Rate limited', (string) $task->error);
    }

    public function test_rate_limit_without_retry_after_uses_default_backoff(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], null));
        $task = $this->makeTask();

        $job = $this->runJob($task);

        $job->assertReleased();
        $this->assertGreaterThanOrEqual(31, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(35, $job->job->releaseDelay);
        $this->assertSame(ExperimentTaskStatus::Pending, $task->refresh()->status);
    }

    public function test_huge_retry_after_is_capped(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 100000));
        $task = $this->makeTask();

        $job = $this->runJob($task);

        $job->assertReleased();
        $this->assertGreaterThanOrEqual(301, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(305, $job->job->releaseDelay);
        $this->assertSame(ExperimentTaskStatus::Pending, $task->refresh()->status);
    }

    public function test_recover_stuck_does_not_fail_rate_limited_task_and_retry_completes_it(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 300));
        $task = $this->makeTask();
        Experiment::withoutGlobalScopes()->whereKey($task->experiment_id)
            ->update(['status' => ExperimentStatus::Building]);
        $stage = ExperimentStage::factory()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $task->experiment_id,
            'stage' => StageType::Building,
            'iteration' => 0,
            'status' => StageStatus::Running,
            'started_at' => now()->subMinutes(15),
            'output_snapshot' => [],
        ]);

        $this->runJob($task)->assertReleased();

        // A long build stage: the task was created well before the recovery cutoff.
        ExperimentTask::withoutGlobalScopes()->whereKey($task->id)
            ->update(['created_at' => now()->subMinutes(15)]);

        $this->artisan('tasks:recover-stuck')->assertSuccessful();

        $this->assertSame(ExperimentTaskStatus::Pending, $task->refresh()->status);
        $this->assertSame(StageStatus::Running, $stage->refresh()->status);
        $this->assertSame(ExperimentStatus::Building, Experiment::withoutGlobalScopes()->find($task->experiment_id)->status);

        // The released job runs again and the provider answers this time.
        $gw = Mockery::mock(AiGatewayInterface::class);
        $gw->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: '{"content":"hello","metadata":{}}',
            parsedOutput: ['content' => 'hello', 'metadata' => []],
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 1,
        ));
        $this->app->instance(AiGatewayInterface::class, $gw);

        $this->runJob($task)->assertNotReleased();

        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Completed, $task->status);
        $this->assertNull($task->error);
    }

    public function test_gateway_internal_rate_limit_still_marks_task_failed_and_rethrows(): void
    {
        // The app's own limiter is out of scope: it keeps the generic failure path.
        $this->bindGatewayThrowing(new RateLimitExceededException('Rate limit exceeded for provider anthropic. Retry after 12s.'));
        $task = $this->makeTask();

        $job = (new BuildArtifactJob($task->experiment_id, $task->id, $task->team_id))->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('expected RateLimitExceededException to propagate');
        } catch (RateLimitExceededException) {
        }

        $job->assertNotReleased();
        $this->assertSame(ExperimentTaskStatus::Failed, $task->refresh()->status);
    }

    public function test_rate_limit_after_a_failed_attempt_clears_stale_completion_fields(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 6));
        $task = $this->makeTask();
        $task->update([
            'status' => ExperimentTaskStatus::Failed,
            'error' => 'boom',
            'duration_ms' => 1234,
            'completed_at' => now(),
        ]);

        $this->runJob($task)->assertReleased();

        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Pending, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertNull($task->duration_ms);
    }

    public function test_non_rate_limit_exception_still_marks_task_failed_and_rethrows(): void
    {
        $this->bindGatewayThrowing(new \RuntimeException('boom'));
        $task = $this->makeTask();

        $job = (new BuildArtifactJob($task->experiment_id, $task->id, $task->team_id))->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('expected RuntimeException to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $job->assertNotReleased();
        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Failed, $task->status);
        $this->assertSame('boom', $task->error);
    }
}
