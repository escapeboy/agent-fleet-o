<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\TriageSentryIssueAction;
use App\Domain\Signal\Enums\SentryFixTaskState;
use App\Domain\Signal\Enums\SentryTriageOutcome;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\SentryFixTask;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * F1-F3: the `executor` switch of the Sentry watchdog triage.
 */
class SentryFixTaskExecutorTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);
        $this->actingAs($user);

        Queue::fake();
        Cache::flush();

        config([
            'sentry_watchdog.mode' => 'phase1',
            'sentry_watchdog.confidence_threshold' => 0.7,
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: json_encode([
                'root_cause' => 'Null dereference in renderer.',
                'confidence' => 0.9,
                'suspect_files' => ['app/Http/Controllers/OrderController.php'],
                'estimated_diff_lines' => 12,
                'is_critical' => true,
                'summary' => 'Renderer crashes on missing field.',
            ], JSON_THROW_ON_ERROR),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 200, completionTokens: 80, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 120,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-5']);
        $this->app->instance(ProviderResolver::class, $resolver);

        GitRepository::create([
            'team_id' => $this->team->id,
            'name' => 'acme/shop',
            'url' => 'https://github.com/acme/shop.git',
            'default_branch' => 'main',
        ]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function integration(array $config = []): Integration
    {
        return Integration::factory()->create([
            'team_id' => $this->team->id,
            'driver' => 'sentry',
            'name' => 'shop-backend',
            'config' => array_merge(['target_repository' => 'acme/shop'], $config),
        ]);
    }

    private function signal(string $issueId = 'sentry-issue-99'): Signal
    {
        return Signal::create([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => 'shop',
            'payload' => [
                'source_type' => 'sentry',
                'source_id' => 'sentry:'.$issueId,
                'payload' => [
                    'id' => $issueId,
                    'title' => 'TypeError: Cannot read property of null',
                    'culprit' => 'App\\Http\\Controllers\\OrderController::show',
                    'level' => 'error',
                    'count' => 5,
                    'permalink' => 'https://sentry.example.com/issues/99/',
                    'metadata' => ['type' => 'TypeError', 'value' => 'Cannot read property of null'],
                ],
            ],
            'content_hash' => hash('sha256', uniqid('sentry-fix-', true)),
            'received_at' => now(),
            'status' => SignalStatus::Received,
        ]);
    }

    private function triage(Signal $signal, Integration $integration)
    {
        return app(TriageSentryIssueAction::class)->execute($signal, 'acme/shop', $integration);
    }

    // F1
    public function test_default_executor_still_creates_an_experiment_and_no_task(): void
    {
        $integration = $this->integration();
        $signal = $this->signal();

        $result = $this->triage($signal, $integration);

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertNotNull($result->experimentId);
        $this->assertNull($result->fixTaskId);
        $this->assertSame(1, Experiment::query()->count());
        $this->assertSame(0, SentryFixTask::withoutGlobalScopes()->count());
    }

    public function test_integration_config_experiment_overrides_global_external(): void
    {
        config(['sentry_watchdog.executor' => 'external']);

        $result = $this->triage($this->signal(), $this->integration(['executor' => 'experiment']));

        $this->assertNotNull($result->experimentId);
        $this->assertSame(0, SentryFixTask::withoutGlobalScopes()->count());
    }

    // F2
    public function test_external_executor_queues_a_task_instead_of_an_experiment(): void
    {
        $integration = $this->integration(['executor' => 'external']);
        $signal = $this->signal();

        $result = $this->triage($signal, $integration);

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertNull($result->experimentId);
        $this->assertSame(0, Experiment::query()->count());

        $task = SentryFixTask::withoutGlobalScopes()->sole();
        $this->assertSame($task->id, $result->fixTaskId);
        $this->assertSame(SentryFixTaskState::Queued, $task->state);
        $this->assertSame('acme/shop', $task->repository);
        $this->assertSame('shop-backend', $task->project);
        $this->assertSame('sentry-issue-99', $task->sentry_issue_id);
        $this->assertSame($integration->id, $task->integration_id);
        $this->assertSame($signal->id, $task->signal_id);
        $this->assertSame('Null dereference in renderer.', $task->root_cause);
        $this->assertSame(['app/Http/Controllers/OrderController.php'], $task->suspect_files);
        $this->assertTrue($task->is_critical);
        $this->assertSame(0, $task->attempts);
        $this->assertCount(1, $task->events);

        $signal->refresh();
        $this->assertSame(SignalStatus::DelegatedToAgent, $signal->status);
        $this->assertNotNull($signal->payload['sentry_watchdog_triaged_at']);
        $this->assertNull($signal->experiment_id);
    }

    public function test_global_external_executor_applies_when_integration_has_no_override(): void
    {
        config(['sentry_watchdog.executor' => 'external']);

        $this->triage($this->signal(), $this->integration());

        $this->assertSame(1, SentryFixTask::withoutGlobalScopes()->count());
        $this->assertSame(0, Experiment::query()->count());
    }

    // F3
    public function test_same_issue_triaged_twice_while_task_is_live_creates_one_task(): void
    {
        $integration = $this->integration(['executor' => 'external']);

        $first = $this->triage($this->signal(), $integration);
        $second = $this->triage($this->signal(), $integration);

        $this->assertSame(SentryTriageOutcome::Delegated, $first->outcome);
        $this->assertSame(SentryTriageOutcome::Skipped, $second->outcome);
        $this->assertSame(1, SentryFixTask::withoutGlobalScopes()->count());
    }

    public function test_same_signal_triaged_twice_is_skipped(): void
    {
        $integration = $this->integration(['executor' => 'external']);
        $signal = $this->signal();

        $this->triage($signal, $integration);
        $again = $this->triage($signal->refresh(), $integration);

        $this->assertSame(SentryTriageOutcome::Skipped, $again->outcome);
        $this->assertSame(1, SentryFixTask::withoutGlobalScopes()->count());
    }

    public function test_new_task_is_allowed_once_the_previous_one_is_terminal(): void
    {
        $integration = $this->integration(['executor' => 'external']);

        $this->triage($this->signal(), $integration);
        SentryFixTask::withoutGlobalScopes()->update(['state' => SentryFixTaskState::Failed->value]);

        $result = $this->triage($this->signal(), $integration);

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertSame(2, SentryFixTask::withoutGlobalScopes()->count());
    }
}
