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
use Tests\TestCase;

/**
 * A provider rate limit is temporary: the job must wait and retry instead of
 * failing the task and using up one of its two allowed exceptions.
 */
class BuildArtifactJobRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_JITTER = 5;

    private const MAX_BASE_DELAY = 300;

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

    private function makeJob(): BuildArtifactJob
    {
        $experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'constraints' => ['llm' => ['provider' => 'anthropic', 'model' => 'test-model']],
        ]);

        $task = ExperimentTask::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'stage' => 'building',
            'name' => 'Artifact',
            'type' => 'code',
            'status' => ExperimentTaskStatus::Queued,
            'input_data' => [
                'plan' => [],
                'artifact_spec' => ['type' => 'code', 'name' => 'Artifact', 'description' => 'Build it'],
            ],
        ]);

        return (new BuildArtifactJob($experiment->id, $task->id, $this->team->id))->withFakeQueueInteractions();
    }

    private function task(BuildArtifactJob $job): ExperimentTask
    {
        return ExperimentTask::withoutGlobalScopes()->findOrFail($job->taskId);
    }

    public function test_rate_limit_releases_job_with_provider_retry_after_and_requeues_task(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 6));
        $job = $this->makeJob();

        $job->handle();

        $job->assertNotFailed();
        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(7, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(6 + self::MAX_JITTER, $job->job->releaseDelay);

        $task = $this->task($job);
        $this->assertSame(ExperimentTaskStatus::Queued, $task->status);
        $this->assertNull($task->started_at);
        $this->assertNull($task->completed_at);
        $this->assertStringContainsString('Rate limited', (string) $task->error);
    }

    public function test_rate_limit_without_retry_after_uses_default_backoff(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], null));
        $job = $this->makeJob();

        $job->handle();

        $this->assertTrue($job->job->isReleased());
        $this->assertGreaterThanOrEqual(31, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(30 + self::MAX_JITTER, $job->job->releaseDelay);
        $this->assertSame(ExperimentTaskStatus::Queued, $this->task($job)->status);
    }

    public function test_huge_retry_after_is_capped(): void
    {
        $this->bindGatewayThrowing(PrismRateLimitedException::make([], 100000));
        $job = $this->makeJob();

        $job->handle();

        $this->assertTrue($job->job->isReleased());
        $this->assertLessThanOrEqual(self::MAX_BASE_DELAY + self::MAX_JITTER, $job->job->releaseDelay);
    }

    public function test_successful_retry_clears_rate_limit_note(): void
    {
        $gw = Mockery::mock(AiGatewayInterface::class);
        $gw->shouldReceive('complete')->once()->andThrow(PrismRateLimitedException::make([], 6));
        $gw->shouldReceive('complete')->once()->andReturn(new AiResponseDTO(
            content: 'done',
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 1),
            provider: 'anthropic',
            model: 'test-model',
            latencyMs: 1,
        ));
        $this->app->instance(AiGatewayInterface::class, $gw);
        $job = $this->makeJob();

        $job->handle();
        $job->handle();

        $task = $this->task($job);
        $this->assertSame(ExperimentTaskStatus::Completed, $task->status);
        $this->assertNull($task->error);
    }

    public function test_non_rate_limit_exception_still_marks_task_failed_and_rethrows(): void
    {
        $this->bindGatewayThrowing(new \RuntimeException('boom'));
        $job = $this->makeJob();

        try {
            $job->handle();
            $this->fail('expected the exception to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $job->assertNotReleased();

        $task = $this->task($job);
        $this->assertSame(ExperimentTaskStatus::Failed, $task->status);
        $this->assertSame('boom', $task->error);
    }
}
