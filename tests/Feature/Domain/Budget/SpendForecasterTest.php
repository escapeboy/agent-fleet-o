<?php

namespace Tests\Feature\Domain\Budget;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Budget\Services\SpendForecaster;
use App\Domain\Shared\Models\Team;
use App\Models\GlobalSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpendForecasterTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test', 'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id, 'settings' => [],
        ]);
    }

    private function entry(LedgerType $type, int $amount): void
    {
        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => 0,
            'description' => 'test',
            'metadata' => [],
        ]);
    }

    public function test_forecast_counts_consumption_entries_not_a_nonexistent_spend_type(): void
    {
        // Net consumed = |(-100) + (-20) + (+30)| = 90. Purchase must be ignored.
        $this->entry(LedgerType::Reservation, -100);
        $this->entry(LedgerType::Deduction, -20);
        $this->entry(LedgerType::Release, 30);
        $this->entry(LedgerType::Purchase, 5000);

        GlobalSetting::set('global_budget_cap', 1000);

        $forecast = app(SpendForecaster::class)->forecast();

        $this->assertSame(90, $forecast['total_spent']);
        $this->assertGreaterThan(0, $forecast['daily_avg_7d']);
        $this->assertNotNull($forecast['days_until_cap']); // was always null while spend read 0
    }

    public function test_forecast_is_zero_without_consumption(): void
    {
        $this->entry(LedgerType::Purchase, 5000); // credit only, no consumption

        $forecast = app(SpendForecaster::class)->forecast();

        $this->assertSame(0, $forecast['total_spent']);
        $this->assertSame(0, $forecast['daily_avg_7d']);
    }
}
