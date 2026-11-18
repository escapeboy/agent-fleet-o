<?php

namespace Tests\Feature\Domain\Signal;

use App\Domain\Integration\Models\Integration;
use App\Domain\Outbound\Services\OutboundCredentialResolver;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Actions\NotifyCriticalSentryIssueAction;
use App\Domain\Signal\Actions\SendSentryWatchdogDigestAction;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Mail\SentryWatchdogMail;
use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Domain\Signal\Models\Signal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TC-30, TC-31 from claudedocs/test-plan-sentry-watchdog.md §6.
 *
 * Default digest channel is email (platform mailer, faked here). The telegram
 * branch is exercised explicitly by overriding the channel config.
 */
class SentryWatchdogNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['sentry_watchdog.digest_email' => 'watchdog@example.test']);
    }

    public function test_tc30_one_digest_for_all_projects_with_news(): void
    {
        Mail::fake();

        $team = Team::factory()->create();
        $busy = $this->finishedRun($team, 'Sentry fleetq', triaged: 7, prs: 3, critical: 1, summary: 'Two new NPEs.');
        $other = $this->finishedRun($team, 'Sentry localz', triaged: 2);
        $quiet = $this->finishedRun($team, 'Sentry utro');

        $result = app(SendSentryWatchdogDigestAction::class)->execute($team->id, collect([$busy, $other, $quiet]));

        $this->assertTrue($result);
        Mail::assertSent(SentryWatchdogMail::class, 1);
        Mail::assertSent(SentryWatchdogMail::class, function (SentryWatchdogMail $mail) {
            return $mail->hasTo('watchdog@example.test')
                && str_contains($mail->htmlBody, '2 of 3 projects with news')
                && str_contains($mail->htmlBody, 'Sentry fleetq')
                && str_contains($mail->htmlBody, 'Triaged: 7')
                && str_contains($mail->htmlBody, 'Critical: 1')
                && str_contains($mail->htmlBody, 'Sentry localz')
                && ! str_contains($mail->htmlBody, 'Sentry utro')
                && str_contains($mail->htmlBody, 'No new issues: 1 projects.');
        });
        $this->assertSame(3, SentryWatchdogRun::withoutGlobalScopes()->whereNotNull('digest_sent_at')->count());
    }

    public function test_no_message_when_no_project_has_news(): void
    {
        Mail::fake();

        $team = Team::factory()->create();
        $runs = collect([$this->finishedRun($team, 'Sentry a'), $this->finishedRun($team, 'Sentry b')]);

        $this->assertFalse(app(SendSentryWatchdogDigestAction::class)->execute($team->id, $runs));
        Mail::assertNothingSent();
        $this->assertSame(2, SentryWatchdogRun::withoutGlobalScopes()->whereNotNull('digest_sent_at')->count());
    }

    public function test_digest_command_sends_one_message_per_team_and_skips_sent_or_running_runs(): void
    {
        Mail::fake();

        $team = Team::factory()->create();
        $this->finishedRun($team, 'Sentry a', triaged: 1);
        $this->finishedRun($team, 'Sentry b', triaged: 4);
        $old = $this->finishedRun($team, 'Sentry old', triaged: 9);
        $old->update(['digest_sent_at' => now()->subDay()]);
        $running = $this->finishedRun($team, 'Sentry running', triaged: 5);
        $running->update(['finished_at' => null]);

        $this->artisan('sentry:watchdog-digest')->assertSuccessful();

        Mail::assertSent(SentryWatchdogMail::class, 1);
        Mail::assertSent(SentryWatchdogMail::class, fn (SentryWatchdogMail $mail) => str_contains($mail->htmlBody, '2 of 2 projects')
            && ! str_contains($mail->htmlBody, 'Sentry old') && ! str_contains($mail->htmlBody, 'Sentry running'));
        $this->assertNull($running->fresh()->digest_sent_at);

        $this->artisan('sentry:watchdog-digest')->assertSuccessful();
        Mail::assertSent(SentryWatchdogMail::class, 1); // nothing new: no second message
    }

    private function finishedRun(Team $team, string $name, int $triaged = 0, int $prs = 0, int $critical = 0, ?string $summary = null): SentryWatchdogRun
    {
        $integration = Integration::factory()->create(['team_id' => $team->id, 'driver' => 'sentry', 'name' => $name]);

        return SentryWatchdogRun::create([
            'integration_id' => $integration->id,
            'team_id' => $team->id,
            'started_at' => now(),
            'finished_at' => now(),
            'signals_triaged' => $triaged,
            'prs_opened' => $prs,
            'investigate_only' => 0,
            'critical_count' => $critical,
            'digest_summary' => $summary,
        ]);
    }

    public function test_tc31_critical_alert_is_deduplicated_per_signal(): void
    {
        Mail::fake();

        $team = Team::factory()->create();
        $signal = $this->seedCriticalSentrySignal($team);

        $action = app(NotifyCriticalSentryIssueAction::class);
        $first = $action->execute($signal);
        $second = $action->execute($signal);

        $this->assertTrue($first, 'First critical alert must send.');
        $this->assertFalse($second, 'Second call for the same signal must be suppressed.');
        Mail::assertSent(SentryWatchdogMail::class, 1);
    }

    public function test_digest_via_telegram_when_channel_overridden(): void
    {
        config(['sentry_watchdog.digest_channel' => 'telegram']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $this->mock(OutboundCredentialResolver::class, function (MockInterface $mock) {
            $mock->shouldReceive('resolve')
                ->andReturn(['bot_token' => 'TEST-BOT-TOKEN', 'chat_id' => '4242']);
        });

        $team = Team::factory()->create();
        $integration = Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'sentry',
            'name' => 'Sentry fleetq',
        ]);
        $run = SentryWatchdogRun::create([
            'integration_id' => $integration->id,
            'team_id' => $team->id,
            'started_at' => now(),
            'finished_at' => now(),
            'signals_triaged' => 4,
            'prs_opened' => 1,
            'investigate_only' => 3,
            'critical_count' => 0,
            'digest_summary' => 'One NPE.',
        ]);

        $result = app(SendSentryWatchdogDigestAction::class)->execute($team->id, collect([$run]));

        $this->assertTrue($result);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains((string) $request['text'], 'Triaged: 4'));
    }

    public function test_telegram_returns_false_when_send_fails(): void
    {
        config(['sentry_watchdog.digest_channel' => 'telegram']);
        Http::fake(['api.telegram.org/*' => Http::response('upstream error', 500)]);

        $this->mock(OutboundCredentialResolver::class, function (MockInterface $mock) {
            $mock->shouldReceive('resolve')
                ->andReturn(['bot_token' => 'TEST-BOT-TOKEN', 'chat_id' => '4242']);
        });

        $team = Team::factory()->create();
        $signal = $this->seedCriticalSentrySignal($team);

        $result = app(NotifyCriticalSentryIssueAction::class)->execute($signal);

        $this->assertFalse($result, 'A failed Telegram send must return false, not throw.');
    }

    private function seedCriticalSentrySignal(Team $team): Signal
    {
        return Signal::create([
            'team_id' => $team->id,
            'source_type' => 'integration',
            'source_identifier' => 'sentry',
            'project_key' => 'fleetq',
            'status' => SignalStatus::Received,
            'content_hash' => md5('sentry-crit-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'source_type' => 'sentry',
                'source_id' => 'sentry:SENTRY-CRIT-1',
                'payload' => [
                    'title' => 'NullPointer in checkout',
                    'level' => 'fatal',
                    'count' => '128',
                    'permalink' => 'https://sentry.karlovo.net/sentry/fleetq/issues/SENTRY-CRIT-1/',
                ],
            ],
            'tags' => ['sentry', 'issue', 'critical'],
        ]);
    }
}
