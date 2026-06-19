<?php

namespace Tests\Feature\Domain\Budget;

use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Services\UpstreamSpendForecaster;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\LlmRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class UpstreamSpendForecasterTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Finance',
            'slug' => 'fin-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id,
            'sub_program_slug' => 'finance',
            'settings' => [],
        ]);
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    private function log(string $provider, string $byokSource, int $credits, int $daysAgo, ?string $teamId = null): void
    {
        LlmRequestLog::withoutGlobalScopes()->create([
            'team_id' => $teamId ?? $this->team->id,
            'agent_id' => $this->agent->id,
            'idempotency_key' => (string) Str::uuid(),
            'provider' => $provider,
            'model' => 'claude-haiku-4-5',
            'status' => 'completed',
            'byok_source' => $byokSource,
            'cost_credits' => $credits,
            'completed_at' => now()->subDays($daysAgo)->setTime(12, 0),
        ]);
    }

    private function forecaster(): UpstreamSpendForecaster
    {
        return app(UpstreamSpendForecaster::class);
    }

    public function test_forecasts_platform_funded_runway(): void
    {
        for ($d = 0; $d <= 9; $d++) {
            $this->log('anthropic', 'platform', 50, $d);
        }

        $f = $this->forecaster()->forecast('finance', 'anthropic', 10000, now()->subDays(60)->toDateString());

        $this->assertSame(50, $f['daily_avg_7d']);
        $this->assertSame(500, $f['spent_since']); // 10 days * 50
        $this->assertSame(9500, $f['remaining']);
        $this->assertSame(190, $f['days_until_depletion']); // ceil(9500 / 50)
    }

    public function test_excludes_byok_override_and_other_subprograms(): void
    {
        for ($d = 0; $d <= 6; $d++) {
            $this->log('anthropic', 'platform', 50, $d);
        }
        $this->log('anthropic', 'team', 9999, 0);             // team BYOK — excluded
        $this->log('anthropic', 'request_override', 9999, 0); // per-request override — excluded

        $user = User::factory()->create();
        $other = Team::create([
            'name' => 'Other', 'slug' => 'oth-'.bin2hex(random_bytes(3)),
            'owner_id' => $user->id, 'sub_program_slug' => 'crm', 'settings' => [],
        ]);
        $this->log('anthropic', 'platform', 9999, 0, $other->id); // other sub-program — excluded

        $f = $this->forecaster()->forecast('finance', 'anthropic', 10000, now()->subDays(60)->toDateString());

        $this->assertSame(350, $f['spent_since']); // only the 7 finance/platform rows
    }

    public function test_null_runway_when_no_recent_spend(): void
    {
        $this->log('anthropic', 'platform', 50, 15); // outside the 7-day window

        $f = $this->forecaster()->forecast('finance', 'anthropic', 10000, now()->subDays(60)->toDateString());

        $this->assertSame(0, $f['daily_avg_7d']);
        $this->assertNull($f['days_until_depletion']);
    }

    public function test_zero_result_when_no_teams_for_subprogram(): void
    {
        $f = $this->forecaster()->forecast('nonexistent', 'anthropic', 10000, now()->subDays(60)->toDateString());

        $this->assertSame(0, $f['spent_since']);
        $this->assertSame(10000, $f['remaining']);
        $this->assertNull($f['days_until_depletion']);
    }
}
