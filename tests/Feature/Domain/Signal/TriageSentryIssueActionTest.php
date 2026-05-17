<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\NotifyCriticalSentryIssueAction;
use App\Domain\Signal\Actions\TriageSentryIssueAction;
use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Enums\SentryTriageOutcome;
use App\Domain\Signal\Enums\SignalStatus;
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
 * Feature coverage for TriageSentryIssueAction — test plan §2 (TC-11..TC-18).
 *
 * The AI gateway is always faked: a Mockery double on AiGatewayInterface returns
 * an AiResponseDTO whose `content` string carries the triage JSON the action
 * parses. ProviderResolver is faked to return a fixed provider/model shape.
 */
class TriageSentryIssueActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        // Phase 1 delegation creates and transitions a real Experiment, which
        // fires ExperimentTransitioned → listeners → queued stage jobs.
        Queue::fake();

        Cache::flush();

        config(['sentry_watchdog.confidence_threshold' => 0.7]);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function fakeGateway(string $content): void
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 200, completionTokens: 80, costCredits: 1),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 120,
        ));
        $this->app->instance(AiGatewayInterface::class, $gateway);

        $resolver = Mockery::mock(ProviderResolver::class);
        $resolver->shouldReceive('resolve')->andReturn([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
        $this->app->instance(ProviderResolver::class, $resolver);
    }

    /**
     * @param  array<string, mixed>  $investigation
     */
    private function triageJson(array $investigation): string
    {
        return json_encode(array_merge([
            'root_cause' => 'Null dereference in the order summary renderer.',
            'confidence' => 0.9,
            'suspect_files' => ['app/Http/Controllers/OrderController.php'],
            'estimated_diff_lines' => 12,
            'is_critical' => false,
            'summary' => 'Order summary crashes on missing line item.',
        ], $investigation), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $overrides
     */
    private function makeSentrySignal(array $payload = [], array $overrides = []): Signal
    {
        return Signal::create(array_merge([
            'team_id' => $this->team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => 'fleetq',
            'payload' => [
                'source_type' => 'sentry',
                'source_id' => 'sentry:'.bin2hex(random_bytes(4)),
                'payload' => array_merge([
                    'id' => 'sentry-issue-42',
                    'title' => 'TypeError: Cannot read property of null',
                    'culprit' => 'App\\Http\\Controllers\\OrderController::show',
                    'level' => 'error',
                    'count' => 7,
                    'permalink' => 'https://sentry.example.com/issues/42/',
                    'metadata' => ['type' => 'TypeError', 'value' => 'Cannot read property of null'],
                ], $payload),
            ],
            'content_hash' => hash('sha256', uniqid('sentry-', true)),
            'received_at' => now(),
            'status' => SignalStatus::Received,
        ], $overrides));
    }

    public function test_tc11_phase0_investigates_only_and_creates_no_experiment(): void
    {
        config(['sentry_watchdog.mode' => 'phase0']);
        $this->fakeGateway($this->triageJson([]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertNull($result->experimentId);
        $this->assertSame(0, Experiment::query()->count());

        $signal->refresh();
        $this->assertNull($signal->experiment_id);
        $this->assertSame(SignalStatus::Received, $signal->status);
    }

    public function test_tc12_phase1_actionable_parent_repo_issue_is_delegated(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        $this->fakeGateway($this->triageJson([
            'suspect_files' => ['app/Http/Controllers/OrderController.php'],
            'confidence' => 0.92,
            'estimated_diff_lines' => 15,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::Delegated, $result->outcome);
        $this->assertTrue($result->wasDelegated());
        $this->assertNotNull($result->experimentId);

        $signal->refresh();
        $this->assertSame($result->experimentId, $signal->experiment_id);
        $this->assertSame(SignalStatus::DelegatedToAgent, $signal->status);
        $this->assertSame(1, Experiment::query()->count());
    }

    public function test_tc13_phase1_base_submodule_suspect_file_is_not_delegated(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        $this->fakeGateway($this->triageJson([
            'suspect_files' => ['base/app/Domain/Agent/Models/Agent.php'],
            'confidence' => 0.95,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertNull($result->experimentId);
        $this->assertSame(FixTier::T4, $result->tier);
        $this->assertSame(0, Experiment::query()->count());

        $signal->refresh();
        $this->assertNull($signal->experiment_id);
    }

    public function test_tc14_phase1_confidence_below_threshold_is_not_delegated(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        config(['sentry_watchdog.confidence_threshold' => 0.7]);
        $this->fakeGateway($this->triageJson([
            'suspect_files' => ['app/Http/Controllers/OrderController.php'],
            'confidence' => 0.4,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertNull($result->experimentId);
        $this->assertSame(0.4, $result->confidence);
        $this->assertSame(0, Experiment::query()->count());
    }

    public function test_tc15_critical_issue_triggers_immediate_notification(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        $this->fakeGateway($this->triageJson([
            'is_critical' => true,
            'suspect_files' => ['base/app/Domain/Signal/Models/Signal.php'],
        ]));

        $signal = $this->makeSentrySignal(['level' => 'fatal']);

        $notify = Mockery::mock(NotifyCriticalSentryIssueAction::class);
        $notify->shouldReceive('execute')
            ->once()
            ->with(Mockery::on(fn (Signal $s) => $s->id === $signal->id))
            ->andReturnTrue();
        $this->app->instance(NotifyCriticalSentryIssueAction::class, $notify);

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertTrue($result->isCritical);
    }

    public function test_tc16_already_delegated_signal_is_skipped(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        $this->fakeGateway($this->triageJson([]));

        $signal = $this->makeSentrySignal([], ['status' => SignalStatus::DelegatedToAgent]);

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::Skipped, $result->outcome);
        $this->assertNull($result->experimentId);
        $this->assertSame(0, Experiment::query()->count());
    }

    public function test_tc16_signal_with_existing_experiment_id_is_skipped(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        $this->fakeGateway($this->triageJson([]));

        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);
        $signal = $this->makeSentrySignal([], [
            'status' => SignalStatus::Received,
            'experiment_id' => $experiment->id,
        ]);

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::Skipped, $result->outcome);
        $this->assertSame(1, Experiment::query()->count());
    }

    public function test_tc17_json_wrapped_in_markdown_fences_is_parsed(): void
    {
        config(['sentry_watchdog.mode' => 'phase0']);

        $inner = $this->triageJson([
            'root_cause' => 'Race condition in cache warm-up.',
            'suspect_files' => ['app/Services/CacheWarmer.php', 'app/Jobs/WarmCacheJob.php'],
            'confidence' => 0.81,
        ]);
        $this->fakeGateway("Here is my analysis:\n```json\n{$inner}\n```\nLet me know if you need more detail.");

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertSame('Race condition in cache warm-up.', $result->rootCause);
        $this->assertSame(0.81, $result->confidence);
        $this->assertSame(
            ['app/Services/CacheWarmer.php', 'app/Jobs/WarmCacheJob.php'],
            $result->suspectFiles,
        );
    }

    public function test_tc17_unparseable_response_degrades_to_investigate_only(): void
    {
        config(['sentry_watchdog.mode' => 'phase1']);
        $this->fakeGateway('the model returned no JSON at all, only prose');

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertSame(0.0, $result->confidence);
        $this->assertSame([], $result->suspectFiles);
        $this->assertSame(0, Experiment::query()->count());
    }

    public function test_tc18_triage_succeeds_through_the_faked_gateway(): void
    {
        config(['sentry_watchdog.mode' => 'phase0']);
        $this->fakeGateway($this->triageJson([
            'root_cause' => 'Unhandled timeout from the upstream pricing API.',
            'confidence' => 0.77,
        ]));

        $signal = $this->makeSentrySignal();

        $result = app(TriageSentryIssueAction::class)->execute($signal);

        $this->assertSame(SentryTriageOutcome::InvestigateOnly, $result->outcome);
        $this->assertSame('Unhandled timeout from the upstream pricing API.', $result->rootCause);
        $this->assertSame(0.77, $result->confidence);
        $this->assertSame($signal->id, $result->signalId);
    }
}
