<?php

namespace Tests\Feature\Domain\Budget;

use App\Domain\Budget\Actions\ReserveBudgetAction;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Exceptions\InsufficientBudgetException;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

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
        $this->actingAs($this->user);
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
        ]);
    }

    public function test_reserves_budget_successfully(): void
    {
        $this->seedBalance(1000);
        $action = new ReserveBudgetAction;

        $entry = $action->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );

        $this->assertInstanceOf(CreditLedger::class, $entry);
        $this->assertEquals(LedgerType::Reservation, $entry->type);
        $this->assertEquals(-100, $entry->amount);
        $this->assertEquals(900, $entry->balance_after);
    }

    public function test_throws_on_insufficient_balance(): void
    {
        $this->seedBalance(50);
        $action = new ReserveBudgetAction;

        $this->expectException(InsufficientBudgetException::class);

        $action->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
        );
    }

    public function test_throws_on_zero_balance(): void
    {
        $action = new ReserveBudgetAction;

        $this->expectException(InsufficientBudgetException::class);

        $action->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 10,
        );
    }

    public function test_reserves_with_experiment_budget_cap(): void
    {
        $this->seedBalance(10000);
        $createExperiment = app(CreateExperimentAction::class);
        $action = new ReserveBudgetAction;

        $experiment = $createExperiment->execute(
            userId: $this->user->id,
            title: 'Budget Test',
            thesis: 'Testing budget',
            track: 'growth',
            budgetCapCredits: 500,
            teamId: $this->team->id,
        );

        $entry = $action->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 100,
            experimentId: $experiment->id,
        );

        $this->assertEquals(9900, $entry->balance_after);
    }

    public function test_throws_when_experiment_budget_exceeded(): void
    {
        $this->seedBalance(10000);
        $createExperiment = app(CreateExperimentAction::class);
        $action = new ReserveBudgetAction;

        $experiment = $createExperiment->execute(
            userId: $this->user->id,
            title: 'Over Budget',
            thesis: 'Testing budget enforcement',
            track: 'growth',
            budgetCapCredits: 100,
            teamId: $this->team->id,
        );

        $this->expectException(InsufficientBudgetException::class);
        $this->expectExceptionMessage('Experiment budget exceeded');

        $action->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 200,
            experimentId: $experiment->id,
        );
    }

    public function test_reservation_creates_ledger_entry(): void
    {
        $this->seedBalance(1000);
        $action = new ReserveBudgetAction;

        $action->execute(
            userId: $this->user->id,
            teamId: $this->team->id,
            amount: 300,
            description: 'Test reservation',
        );

        $this->assertDatabaseHas('credit_ledgers', [
            'team_id' => $this->team->id,
            'type' => LedgerType::Reservation->value,
            'description' => 'Test reservation',
        ]);
    }
}
