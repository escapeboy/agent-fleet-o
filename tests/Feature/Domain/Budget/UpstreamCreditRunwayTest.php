<?php

namespace Tests\Feature\Domain\Budget;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Actions\CheckUpstreamCreditRunwayAction;
use App\Domain\Budget\Notifications\UpstreamCreditLowNotification;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpstreamCreditRunwayTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Finance',
            'slug' => 'fin-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'sub_program_slug' => 'finance',
            'settings' => [],
        ]);
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);

        config([
            'credit_alerts.enabled' => true,
            'credit_alerts.recipient' => 'ops@fleetq.test',
            'credit_alerts.threshold_days' => [14, 7, 3],
            'credit_alerts.cooldown_hours' => 24,
        ]);
    }

    private function seedDailyPlatformSpend(int $perDay, int $days): void
    {
        for ($d = 0; $d < $days; $d++) {
            LlmRequestLog::withoutGlobalScopes()->create([
                'team_id' => $this->team->id,
                'agent_id' => $this->agent->id,
                'idempotency_key' => (string) Str::uuid(),
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5',
                'status' => 'completed',
                'byok_source' => 'platform',
                'cost_credits' => $perDay,
                'completed_at' => now()->subDays($d)->setTime(12, 0),
            ]);
        }
    }

    private function setBudget(int $credits): void
    {
        config(['credit_alerts.budgets' => [
            'finance' => ['anthropic' => ['credits' => $credits, 'since' => now()->subDays(7)->toDateString()]],
        ]]);
    }

    private function action(): CheckUpstreamCreditRunwayAction
    {
        return app(CheckUpstreamCreditRunwayAction::class);
    }

    public function test_sends_alert_when_runway_low(): void
    {
        $this->seedDailyPlatformSpend(100, 7); // avg7 = 100, spent_since = 700
        $this->setBudget(1200);                // remaining 500 -> ~5 days -> bucket 7

        $summaries = $this->action()->execute();

        $this->assertTrue($summaries[0]['alerted']);
        $this->assertSame(7, $summaries[0]['alert_bucket']);
        Notification::assertSentOnDemand(
            UpstreamCreditLowNotification::class,
            fn (UpstreamCreditLowNotification $n) => $n->subProgram === 'finance' && $n->provider === 'anthropic',
        );
    }

    public function test_no_alert_when_runway_safe(): void
    {
        $this->seedDailyPlatformSpend(100, 7);
        $this->setBudget(1_000_000); // years of runway

        $summaries = $this->action()->execute();

        $this->assertNull($summaries[0]['alert_bucket']);
        Notification::assertNothingSent();
    }

    public function test_dedups_within_cooldown(): void
    {
        $this->seedDailyPlatformSpend(100, 7);
        $this->setBudget(1200);

        $this->action()->execute();
        $this->action()->execute(); // same bucket, within cooldown

        Notification::assertCount(1);
    }

    public function test_dry_run_sends_nothing(): void
    {
        $this->seedDailyPlatformSpend(100, 7);
        $this->setBudget(1200);

        $summaries = $this->action()->execute(dryRun: true);

        $this->assertNotEmpty($summaries);
        Notification::assertNothingSent();
    }

    public function test_disabled_is_noop(): void
    {
        $this->seedDailyPlatformSpend(100, 7);
        $this->setBudget(1200);
        config(['credit_alerts.enabled' => false]);

        $this->assertSame([], $this->action()->execute());
        Notification::assertNothingSent();
    }
}
