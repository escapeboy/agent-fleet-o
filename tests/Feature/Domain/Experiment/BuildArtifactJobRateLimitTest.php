<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentTask;
use App\Domain\Experiment\Pipeline\BuildArtifactJob;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * A provider rate limit is temporary: the artifact build must go back to the
 * queue after the provider's retry-after instead of failing the task.
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

    private function makeTask(): ExperimentTask
    {
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'title' => 'Build things',
            'thesis' => 'Things help',
            'constraints' => ['llm' => ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']],
        ]);

        return ExperimentTask::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => 'building',
            'name' => 'Landing page',
            'type' => 'design',
            'status' => ExperimentTaskStatus::Pending,
            'input_data' => [
                'plan' => [],
                'artifact_spec' => ['type' => 'design', 'name' => 'Landing page', 'description' => 'A page'],
            ],
        ]);
    }

    private function bindGatewayThrowing(Throwable $e): void
    {
        $gw = Mockery::mock(AiGatewayInterface::class);
        $gw->shouldReceive('complete')->andThrow($e);
        $this->app->instance(AiGatewayInterface::class, $gw);
    }

    private function runJob(ExperimentTask $task): BuildArtifactJob
    {
        $job = (new BuildArtifactJob($task->experiment_id, $task->id, $this->team->id))
            ->withFakeQueueInteractions();
        $job->handle();

        return $job;
    }

    public function test_rate_limit_releases_job_with_provider_retry_after_and_requeues_task(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 6));
        $task = $this->makeTask();

        $job = $this->runJob($task);

        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(7, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(6 + BuildArtifactJob::RATE_LIMIT_MAX_JITTER, $job->job->releaseDelay);

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

        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(31, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(30 + BuildArtifactJob::RATE_LIMIT_MAX_JITTER, $job->job->releaseDelay);
        $this->assertSame(ExperimentTaskStatus::Pending, $task->refresh()->status);
    }

    public function test_huge_retry_after_is_capped(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 100000));
        $task = $this->makeTask();

        $job = $this->runJob($task);

        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(BuildArtifactJob::RATE_LIMIT_MAX_DELAY + 1, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(
            BuildArtifactJob::RATE_LIMIT_MAX_DELAY + BuildArtifactJob::RATE_LIMIT_MAX_JITTER,
            $job->job->releaseDelay,
        );
        $this->assertSame(ExperimentTaskStatus::Pending, $task->refresh()->status);
    }

    public function test_successful_retry_clears_rate_limit_error(): void
    {
        $task = $this->makeTask();
        $task->update(['error' => 'Rate limited by provider; retrying in 7s']);

        $gw = Mockery::mock(AiGatewayInterface::class);
        $gw->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: '{"content":"Hello","metadata":{}}',
            parsedOutput: ['content' => 'Hello', 'metadata' => []],
            usage: new AiUsageDTO(promptTokens: 10, completionTokens: 5, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 100,
        ));
        $this->app->instance(AiGatewayInterface::class, $gw);

        $job = $this->runJob($task);

        $this->assertFalse($job->job->isReleased());
        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Completed, $task->status);
        $this->assertNull($task->error);
    }

    public function test_stuck_task_cleanup_does_not_fail_a_rate_limited_task_waiting_to_retry(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 6));
        $task = $this->makeTask();
        // Later artifacts in the sequential chain are routinely older than the cleanup timeout.
        $task->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $job = $this->runJob($task);
        $this->assertTrue($job->job->isReleased());

        $this->artisan('tasks:recover-stuck')->assertExitCode(0);

        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Pending, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertStringContainsString('Rate limited', (string) $task->error);
    }

    public function test_non_rate_limit_exception_still_marks_task_failed_and_rethrows(): void
    {
        $this->bindGatewayThrowing(new RuntimeException('boom'));
        $task = $this->makeTask();

        $job = (new BuildArtifactJob($task->experiment_id, $task->id, $this->team->id))
            ->withFakeQueueInteractions();

        try {
            $job->handle();
            $this->fail('Expected RuntimeException to be re-thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertFalse($job->job->isReleased());

        $task->refresh();
        $this->assertSame(ExperimentTaskStatus::Failed, $task->status);
        $this->assertSame('boom', $task->error);
    }
}
