<?php

namespace Tests\Feature\Domain\Signal;

use App\Console\Commands\RunSentryWatchdog;
use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\SendSentryWatchdogDigestAction;
use App\Domain\Signal\Actions\TriageSentryIssueAction;
use App\Domain\Signal\DTOs\SentryTriageResult;
use App\Domain\Signal\Enums\FixTier;
use App\Domain\Signal\Enums\SentryTriageOutcome;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Jobs\RunSentryWatchdogJob;
use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TC-19..TC-22 from test-plan-sentry-watchdog.md §3 (RunSentryWatchdogJob)
 * and TC-27..TC-29 §5 (sentry:watchdog command).
 *
 * The job is isolated from the LLM by binding a Mockery double for
 * TriageSentryIssueAction into the container; SendSentryWatchdogDigestAction
 * is faked so no outbound is attempted.
 */
class RunSentryWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    public function test_tc19_groups_signals_by_sentry_issue_id_and_writes_one_run(): void
    {
        $integration = $this->seedSentryIntegration();

        $this->seedSentrySignal('ISSUE-A');
        $this->seedSentrySignal('ISSUE-A');
        $this->seedSentrySignal('ISSUE-B');
        $this->seedSentrySignal('ISSUE-C');
        $this->seedSentrySignal('ISSUE-D');

        $triageCalls = 0;
        $this->mockTriage(function (Signal $signal) use (&$triageCalls) {
            $triageCalls++;

            return $this->investigateResult($signal->id);
        });
        $this->fakeDigest();

        $this->runJob($integration->id);

        $this->assertSame(4, $triageCalls, 'Each distinct Sentry issue id is triaged exactly once.');

        $runs = SentryWatchdogRun::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->get();
        $this->assertCount(1, $runs);

        $run = $runs->first();
        $this->assertSame(4, $run->signals_triaged);
        $this->assertSame(0, $run->prs_opened);
        $this->assertSame(4, $run->investigate_only);
        $this->assertSame(0, $run->critical_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_tc20_no_new_signals_completes_cleanly_with_zero_counts(): void
    {
        $integration = $this->seedSentryIntegration();

        $triageCalls = 0;
        $this->mockTriage(function (Signal $signal) use (&$triageCalls) {
            $triageCalls++;

            return $this->investigateResult($signal->id);
        });

        $digestCalls = 0;
        $this->mockDigest(function (SentryWatchdogRun $run) use (&$digestCalls) {
            $digestCalls++;

            return true;
        });

        $this->runJob($integration->id);

        $this->assertSame(0, $triageCalls, 'No signals → triage never invoked.');
        $this->assertSame(1, $digestCalls, 'Digest still fires for an empty run.');

        $run = SentryWatchdogRun::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->firstOrFail();
        $this->assertSame(0, $run->signals_triaged);
        $this->assertSame(0, $run->prs_opened);
        $this->assertSame(0, $run->investigate_only);
        $this->assertSame(0, $run->critical_count);
        $this->assertNotNull($run->finished_at);
    }

    public function test_tc21_job_is_guarded_by_without_overlapping_keyed_on_integration(): void
    {
        $integrationId = (string) Str::uuid7();
        $job = new RunSentryWatchdogJob($integrationId);

        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        $reflection = new \ReflectionProperty(WithoutOverlapping::class, 'key');
        $reflection->setAccessible(true);
        $this->assertSame($integrationId, $reflection->getValue($middleware[0]));
    }

    public function test_tc22_triage_throwing_for_one_signal_does_not_abort_the_batch(): void
    {
        $integration = $this->seedSentryIntegration();

        $this->seedSentrySignal('ISSUE-OK1');
        $this->seedSentrySignal('ISSUE-BOOM');
        $this->seedSentrySignal('ISSUE-OK2');

        $processed = 0;
        $this->mockTriage(function (Signal $signal) use (&$processed) {
            if (($signal->payload['payload']['id'] ?? null) === 'ISSUE-BOOM') {
                throw new \RuntimeException('triage exploded');
            }
            $processed++;

            return $this->investigateResult($signal->id);
        });
        $this->fakeDigest();

        $this->runJob($integration->id);

        $this->assertSame(2, $processed, 'The two healthy signals are still triaged.');

        $run = SentryWatchdogRun::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->firstOrFail();
        $this->assertSame(2, $run->signals_triaged);
        $this->assertSame(2, $run->investigate_only);
        $this->assertNotNull($run->finished_at, 'Run is finalised — a single bad signal does not fail it.');
    }

    public function test_tc27_command_dispatches_only_for_watchdog_enabled_integrations(): void
    {
        Queue::fake();

        $enabled = $this->seedSentryIntegration(['watchdog_enabled' => true], 'Sentry Enabled');
        $disabled = $this->seedSentryIntegration(['watchdog_enabled' => false], 'Sentry Disabled');

        $this->artisan('sentry:watchdog')->assertExitCode(RunSentryWatchdog::SUCCESS);

        Queue::assertPushed(RunSentryWatchdogJob::class, 1);
        Queue::assertPushed(
            RunSentryWatchdogJob::class,
            fn (RunSentryWatchdogJob $job) => $job->integrationId === $enabled->id,
        );
        Queue::assertNotPushed(
            RunSentryWatchdogJob::class,
            fn (RunSentryWatchdogJob $job) => $job->integrationId === $disabled->id,
        );
    }

    public function test_tc28_project_option_filters_integrations_by_name(): void
    {
        Queue::fake();

        $fleetq = $this->seedSentryIntegration(['watchdog_enabled' => true], 'Sentry fleetq prod');
        $other = $this->seedSentryIntegration(['watchdog_enabled' => true], 'Sentry actiobg');

        $this->artisan('sentry:watchdog', ['--project' => 'fleetq'])
            ->assertExitCode(RunSentryWatchdog::SUCCESS);

        Queue::assertPushed(RunSentryWatchdogJob::class, 1);
        Queue::assertPushed(
            RunSentryWatchdogJob::class,
            fn (RunSentryWatchdogJob $job) => $job->integrationId === $fleetq->id,
        );
        Queue::assertNotPushed(
            RunSentryWatchdogJob::class,
            fn (RunSentryWatchdogJob $job) => $job->integrationId === $other->id,
        );
    }

    public function test_run_skips_signals_matching_ignore_title_patterns(): void
    {
        // Default config ignores "Cron failure:" boilerplate that Sentry's
        // server-side monitor configs emit even after sentryMonitor() was
        // removed from the codebase. The LLM defaults to 0.0 confidence on
        // these — pure noise that pollutes the digest.
        $integration = $this->seedSentryIntegration();

        $this->seedSentrySignal('CRON-1', ['title' => 'Cron failure: claude-vps-check-token']);
        $this->seedSentrySignal('CRON-2', ['title' => 'Cron failure: tasks-recover-stuck']);
        $this->seedSentrySignal('REAL', ['title' => 'NullPointer in checkout']);

        $triagedTitles = [];
        $this->mockTriage(function (Signal $signal) use (&$triagedTitles) {
            $triagedTitles[] = $signal->payload['payload']['title'] ?? '';

            return $this->investigateResult($signal->id);
        });
        $this->fakeDigest();

        $this->runJob($integration->id);

        $this->assertSame(['NullPointer in checkout'], $triagedTitles, 'Only the non-noise signal should reach triage.');

        $run = SentryWatchdogRun::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->firstOrFail();
        $this->assertSame(1, $run->signals_triaged);
    }

    public function test_run_respects_custom_ignore_patterns(): void
    {
        // The default pattern set can be replaced — patterns are ILIKE so
        // operators can suppress whole prefix families.
        config(['sentry_watchdog.ignore_title_patterns' => ['%deprecated%', 'NullPointer%']]);

        $integration = $this->seedSentryIntegration();
        $this->seedSentrySignal('IGNORE-1', ['title' => 'This deprecated API call']);
        $this->seedSentrySignal('IGNORE-2', ['title' => 'NullPointer in legacy module']);
        $this->seedSentrySignal('KEEP', ['title' => 'OAuth refresh failed']);

        $triagedTitles = [];
        $this->mockTriage(function (Signal $signal) use (&$triagedTitles) {
            $triagedTitles[] = $signal->payload['payload']['title'] ?? '';

            return $this->investigateResult($signal->id);
        });
        $this->fakeDigest();

        $this->runJob($integration->id);

        $this->assertSame(['OAuth refresh failed'], $triagedTitles);
    }

    public function test_run_collects_critical_signals_into_digest_summary(): void
    {
        $integration = $this->seedSentryIntegration();

        $this->seedSentrySignal('ISSUE-CRIT-A', [
            'title' => 'Auth boot crash',
            'permalink' => 'https://sentry.karlovo.net/sentry/fleetq/issues/CRIT-A/',
        ]);
        $this->seedSentrySignal('ISSUE-CRIT-B', [
            'title' => 'Migration deadlock',
            'permalink' => 'https://sentry.karlovo.net/sentry/fleetq/issues/CRIT-B/',
        ]);
        $this->seedSentrySignal('ISSUE-NORMAL'); // non-critical baseline

        $this->mockTriage(function (Signal $signal) {
            $title = $signal->payload['payload']['title'] ?? '';
            $isCritical = str_contains($title, 'Auth boot crash') || str_contains($title, 'Migration deadlock');

            return new SentryTriageResult(
                signalId: $signal->id,
                outcome: SentryTriageOutcome::InvestigateOnly,
                tier: FixTier::T4,
                confidence: 0.4,
                isCritical: $isCritical,
                summary: $title ?: 'investigate-only',
            );
        });
        $this->fakeDigest();

        $this->runJob($integration->id);

        $run = SentryWatchdogRun::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->firstOrFail();

        $this->assertSame(2, $run->critical_count);
        $this->assertSame(3, $run->signals_triaged);

        // Critical issues are surfaced as a header section at the top of the digest
        // so they are the first thing the user sees in Telegram AND the watchdog UI.
        $this->assertStringContainsString('Critical issues:', $run->digest_summary);
        $this->assertStringContainsString('Auth boot crash', $run->digest_summary);
        $this->assertStringContainsString('Migration deadlock', $run->digest_summary);
        $this->assertStringContainsString('https://sentry.karlovo.net/sentry/fleetq/issues/CRIT-A/', $run->digest_summary);
        $this->assertStringContainsString('https://sentry.karlovo.net/sentry/fleetq/issues/CRIT-B/', $run->digest_summary);
    }

    public function test_tc29_command_exits_cleanly_with_no_active_sentry_integrations(): void
    {
        Queue::fake();

        Integration::factory()->for($this->team)->create([
            'driver' => 'github',
            'status' => IntegrationStatus::Active,
            'config' => ['watchdog_enabled' => true],
        ]);
        Integration::factory()->for($this->team)->create([
            'driver' => 'sentry',
            'status' => IntegrationStatus::Disconnected,
            'config' => ['watchdog_enabled' => true],
        ]);

        $this->artisan('sentry:watchdog')
            ->expectsOutputToContain('No enabled Sentry integrations to watch.')
            ->assertExitCode(RunSentryWatchdog::SUCCESS);

        Queue::assertNothingPushed();
    }

    public function test_command_dispatches_one_job_per_team(): void
    {
        Queue::fake();

        // Two enabled Sentry integrations on the same team. The job triages
        // every Sentry signal for a team, so only one job must be dispatched.
        $this->seedSentryIntegration(['watchdog_enabled' => true], 'Sentry fleetq');
        $this->seedSentryIntegration(['watchdog_enabled' => true], 'Sentry signalio-backend');

        $this->artisan('sentry:watchdog')->assertExitCode(RunSentryWatchdog::SUCCESS);

        Queue::assertPushed(RunSentryWatchdogJob::class, 1);
    }

    public function test_run_caps_triage_at_max_signals_per_run(): void
    {
        config(['sentry_watchdog.max_signals_per_run' => 3]);
        $integration = $this->seedSentryIntegration();

        foreach (['I1', 'I2', 'I3', 'I4', 'I5'] as $issueId) {
            $this->seedSentrySignal($issueId);
        }

        $triageCalls = 0;
        $this->mockTriage(function (Signal $signal) use (&$triageCalls) {
            $triageCalls++;

            return $this->investigateResult($signal->id);
        });
        $this->fakeDigest();

        $this->runJob($integration->id);

        $this->assertSame(3, $triageCalls, 'A run triages at most max_signals_per_run signals.');

        $run = SentryWatchdogRun::withoutGlobalScopes()
            ->where('integration_id', $integration->id)
            ->firstOrFail();
        $this->assertSame(3, $run->signals_triaged);
    }

    private function runJob(string $integrationId): void
    {
        app()->call([new RunSentryWatchdogJob($integrationId), 'handle']);
    }

    private function mockTriage(callable $handler): void
    {
        $this->mock(TriageSentryIssueAction::class, function (MockInterface $mock) use ($handler) {
            $mock->shouldReceive('execute')->andReturnUsing($handler);
        });
    }

    private function mockDigest(callable $handler): void
    {
        $this->mock(SendSentryWatchdogDigestAction::class, function (MockInterface $mock) use ($handler) {
            $mock->shouldReceive('execute')->andReturnUsing($handler);
        });
    }

    private function fakeDigest(): void
    {
        $this->mockDigest(fn (SentryWatchdogRun $run) => true);
    }

    private function investigateResult(string $signalId): SentryTriageResult
    {
        return new SentryTriageResult(
            signalId: $signalId,
            outcome: SentryTriageOutcome::InvestigateOnly,
            tier: FixTier::T4,
            confidence: 0.4,
            isCritical: false,
            summary: 'investigate-only canned result',
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function seedSentryIntegration(array $config = ['watchdog_enabled' => true], string $name = 'Sentry fleetq'): Integration
    {
        return Integration::factory()->for($this->team)->create([
            'driver' => 'sentry',
            'name' => $name,
            'status' => IntegrationStatus::Active,
            'config' => $config,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides  merged into the inner Sentry payload.
     */
    private function seedSentrySignal(string $sentryIssueId, array $payloadOverrides = []): Signal
    {
        $innerPayload = array_merge([
            'id' => $sentryIssueId,
            'title' => 'NullPointer in checkout',
        ], $payloadOverrides);

        return Signal::create([
            'team_id' => $this->team->id,
            'experiment_id' => null,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'status' => SignalStatus::Received,
            'payload' => [
                'source_type' => 'sentry',
                'source_id' => 'sentry:'.$sentryIssueId,
                'tags' => ['sentry', 'issue'],
                'payload' => $innerPayload,
            ],
            'content_hash' => md5('sentry-'.bin2hex(random_bytes(6))),
            'tags' => ['sentry', 'issue'],
            'received_at' => now(),
        ]);
    }
}
