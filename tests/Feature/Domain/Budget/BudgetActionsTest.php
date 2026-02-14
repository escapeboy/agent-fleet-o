<?php

namespace Tests\Feature\Domain\Budget;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Actions\SettleBudgetAction;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private ReserveBudgetAction $reserveAction;

    private SettleBudgetAction $settleAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        $this->reserveAction = app(ReserveBudgetAction::class);
        $this->settleAction = app(SettleBudgetAction::class);
    }

    private function seedBalance(int $amount): void
    {
        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => $amount,
            'balance_after' => $amount,
            'description' => 'Initial balance',
            'metadata' => [],
            'created_at' => now()->subDay(),
        ]);
    }

    public function test_can_reserve_budget(): void
    {
        $this->seedBalance(1000);

        $reservation = $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );

        $this->assertEquals(LedgerType::Reservation, $reservation->type);
        $this->assertEquals(-100, $reservation->amount);
        $this->assertEquals(900, $reservation->balance_after);
    }

    public function test_cannot_reserve_more_than_balance(): void
    {
        $this->seedBalance(50);

        $this->expectException(InsufficientBudgetException::class);

        $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );
    }

    public function test_cannot_reserve_with_zero_balance(): void
    {
        $this->expectException(InsufficientBudgetException::class);

        $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 10,
        );
    }

    public function test_settle_creates_release_entry_when_actual_less_than_reserved(): void
    {
        $this->seedBalance(1000);

        $reservation = $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );

        $this->settleAction->execute($reservation, actualCost: 60);

        // A Release entry should be created with the difference
        $releaseEntry = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('type', LedgerType::Release)
            ->first();

        $this->assertNotNull($releaseEntry);
        $this->assertEquals(40, $releaseEntry->amount);
    }

    public function test_settle_creates_deduction_entry_when_actual_more_than_reserved(): void
    {
        $this->seedBalance(1000);

        $reservation = $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );

        $this->settleAction->execute($reservation, actualCost: 150);

        $deductionEntry = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->where('type', LedgerType::Deduction)
            ->first();

        $this->assertNotNull($deductionEntry);
        $this->assertEquals(-50, $deductionEntry->amount);
    }

    public function test_settle_creates_no_entry_when_exact_match(): void
    {
        $this->seedBalance(1000);

        $reservation = $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );

        $initialCount = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->count();

        $this->settleAction->execute($reservation, actualCost: 100);

        $finalCount = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $this->team->id)
            ->count();

        $this->assertEquals($initialCount, $finalCount);
    }

    public function test_sequential_reservations_deduct_correctly(): void
    {
        $this->seedBalance(1000);

        $r1 = $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 200,
        );

        $this->assertEquals(LedgerType::Reservation, $r1->type);
        $this->assertEquals(-200, $r1->amount);
        $this->assertEquals(800, $r1->balance_after);
    }

    public function test_settle_tracks_experiment_spending(): void
    {
        $this->seedBalance(1000);

        $experiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Budget Test',
            'status' => 'draft',
            'track' => 'growth',
            'execution_mode' => 'auto',
            'hypothesis' => 'Test',
            'config' => [],
            'constraints' => [],
            'budget_cap_credits' => 0,
            'budget_spent_credits' => 0,
            'current_iteration' => 1,
            'max_iterations' => 10,
        ]);

        $reservation = $this->reserveAction->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
            experimentId: $experiment->id,
        );

        $this->settleAction->execute($reservation, actualCost: 75);

        $experiment->refresh();
        $this->assertEquals(75, $experiment->budget_spent_credits);
    }
}
