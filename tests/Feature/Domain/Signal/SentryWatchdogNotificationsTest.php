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

    public function test_tc30_digest_is_emailed_with_run_counts(): void
    {
        Mail::fake();

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
            'signals_triaged' => 7,
            'prs_opened' => 3,
            'investigate_only' => 2,
            'critical_count' => 1,
            'digest_summary' => 'Two new NPEs in checkout flow.',
        ]);

        $result = app(SendSentryWatchdogDigestAction::class)->execute($run);

        $this->assertTrue($result);
        Mail::assertSent(SentryWatchdogMail::class, function (SentryWatchdogMail $mail) {
            return $mail->hasTo('watchdog@example.test')
                && str_contains($mail->subjectLine, 'Sentry fleetq')
                && str_contains($mail->htmlBody, 'Triaged: 7')
                && str_contains($mail->htmlBody, 'PRs opened: 3')
                && str_contains($mail->htmlBody, 'Critical: 1');
        });
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

        $result = app(SendSentryWatchdogDigestAction::class)->execute($run);

        $this->assertTrue($result);
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
            'source_type' => 'sentry',
            'source_identifier' => 'sentry:fleetq:SENTRY-CRIT-1',
            'project_key' => 'fleetq',
            'status' => SignalStatus::Received,
            'content_hash' => md5('sentry-crit-'.bin2hex(random_bytes(6))),
            'received_at' => now(),
            'payload' => [
                'title' => 'NullPointer in checkout',
                'level' => 'fatal',
                'count' => '128',
                'permalink' => 'https://sentry.karlovo.net/sentry/fleetq/issues/SENTRY-CRIT-1/',
            ],
            'tags' => ['sentry', 'issue', 'critical'],
        ]);
    }
}
