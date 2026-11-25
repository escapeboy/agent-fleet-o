<?php

declare(strict_types=1);

namespace Tests\Feature\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\CollectCrewArtifactsAction;
use App\Domain\Crew\Actions\SynthesizeResultAction;
use App\Domain\Crew\Actions\ValidateTaskOutputAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Events\CrewExecuted;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Services\CrewOrchestrator;
use App\Domain\Shared\Models\Team;
use App\Domain\Shared\Services\NotificationService;
use App\Http\Resources\Api\V1\CrewExecutionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Mockery;
use Tests\TestCase;

class CrewFinalQaRetryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Crew $crew;

    private int $qaCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Final QA Team',
            'slug' => 'final-qa-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        $coordinator = Agent::factory()->for($this->team)->create();
        $qa = Agent::factory()->for($this->team)->create();

        $this->crew = Crew::factory()->for($this->team)->create([
            'user_id' => $user->id,
            'coordinator_agent_id' => $coordinator->id,
            'qa_agent_id' => $qa->id,
        ]);

        Sleep::fake();
        Event::fake([CrewExecuted::class]);

        $synth = Mockery::mock(SynthesizeResultAction::class);
        $synth->shouldReceive('execute')->andReturn(['result' => ['text' => 'ok'], 'cost' => 0]);
        $this->app->instance(SynthesizeResultAction::class, $synth);

        $artifacts = Mockery::mock(CollectCrewArtifactsAction::class);
        $artifacts->shouldReceive('execute')->andReturn(collect());
        $this->app->instance(CollectCrewArtifactsAction::class, $artifacts);

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('notifyTeam');
        $this->app->instance(NotificationService::class, $notifications);
    }

    /**
     * @param  array<string, mixed>  $errorMetadata
     */
    private function makeExecution(array $errorMetadata = []): CrewExecution
    {
        return CrewExecution::create([
            'team_id' => $this->team->id,
            'crew_id' => $this->crew->id,
            'goal' => 'Write a report',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => ['process_type' => 'sequential'],
            'started_at' => now(),
            'error_metadata' => $errorMetadata ?: null,
        ]);
    }

    /**
     * @param  \Closure(int): array<string, mixed>  $behaviour
     */
    private function bindQa(\Closure $behaviour): void
    {
        $this->qaCalls = 0;
        $validator = Mockery::mock(ValidateTaskOutputAction::class);
        $validator->shouldReceive('execute')->andReturnUsing(function () use ($behaviour) {
            $this->qaCalls++;

            return $behaviour($this->qaCalls);
        });
        $this->app->instance(ValidateTaskOutputAction::class, $validator);
    }

    private function runSynthesis(CrewExecution $execution): CrewExecution
    {
        app(CrewOrchestrator::class)->synthesizeAndComplete($execution);

        return $execution->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private static function qaResult(bool $passed, float $score): array
    {
        return ['passed' => $passed, 'score' => $score, 'feedback' => 'fb', 'issues' => [], 'unverified' => []];
    }

    public function test_qa_throws_once_then_succeeds_uses_retry_score(): void
    {
        $this->bindQa(function (int $call) {
            if ($call === 1) {
                throw new \RuntimeException('transient');
            }

            return self::qaResult(true, 0.8);
        });

        $execution = $this->runSynthesis($this->makeExecution());

        $this->assertSame(2, $this->qaCalls);
        $this->assertSame(CrewExecutionStatus::Completed, $execution->status);
        $this->assertEqualsWithDelta(0.8, (float) $execution->quality_score, 0.0001);
        $this->assertNull(data_get($execution->error_metadata, 'qa_error'));
    }

    public function test_qa_keeps_throwing_marks_qa_error_without_zero_score(): void
    {
        $this->bindQa(function (): array {
            throw new \RuntimeException('provider timeout');
        });

        $execution = $this->runSynthesis($this->makeExecution());

        $this->assertSame(2, $this->qaCalls);
        $this->assertSame(CrewExecutionStatus::Completed, $execution->status);
        $this->assertNull($execution->quality_score);
        $this->assertStringContainsString('provider timeout', (string) data_get($execution->error_metadata, 'qa_error.message'));
        $this->assertSame(\RuntimeException::class, data_get($execution->error_metadata, 'qa_error.exception'));
        $this->assertSame(2, data_get($execution->error_metadata, 'qa_error.attempts'));
    }

    public function test_low_qa_score_is_kept_unchanged(): void
    {
        $this->bindQa(fn (): array => self::qaResult(false, 0.2));

        $execution = $this->runSynthesis($this->makeExecution());

        $this->assertSame(1, $this->qaCalls);
        $this->assertSame(CrewExecutionStatus::Completed, $execution->status);
        $this->assertEqualsWithDelta(0.2, (float) $execution->quality_score, 0.0001);
        $this->assertNull(data_get($execution->error_metadata, 'qa_error'));
    }

    public function test_qa_keeps_throwing_does_not_erase_existing_error_metadata(): void
    {
        $this->bindQa(function (): array {
            throw new \RuntimeException('provider timeout');
        });

        $execution = $this->runSynthesis($this->makeExecution(['sentry_event_id' => 'x']));

        $this->assertSame('x', data_get($execution->error_metadata, 'sentry_event_id'));
        $this->assertNotNull(data_get($execution->error_metadata, 'qa_error'));
    }

    public function test_api_resource_exposes_qa_error(): void
    {
        $qaError = ['message' => 'provider timeout', 'exception' => \RuntimeException::class, 'attempts' => 2, 'failed_at' => now()->toIso8601String()];

        $failed = $this->makeExecution(['qa_error' => $qaError]);
        $normal = $this->makeExecution();

        $failedArray = (new CrewExecutionResource($failed))->toArray(request());
        $normalArray = (new CrewExecutionResource($normal))->toArray(request());

        $this->assertArrayHasKey('qa_error', $failedArray);
        $this->assertSame($qaError, $failedArray['qa_error']);
        $this->assertArrayHasKey('qa_error', $normalArray);
        $this->assertNull($normalArray['qa_error']);
    }
}
