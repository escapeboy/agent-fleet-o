<?php

namespace Tests\Feature\Jobs\Middleware;

use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Jobs\Middleware\CheckBudgetAvailable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckBudgetAvailableTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team-budget',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $this->experiment = Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $user->id,
            'title' => 'Test Experiment',
            'status' => 'scoring',
            'track' => 'growth',
            'budget_cap_credits' => 0,
            'budget_spent_credits' => 0,
        ]);
    }

    private function runMiddleware(object $job): bool
    {
        $called = false;
        (new CheckBudgetAvailable)->handle($job, function () use (&$called) {
            $called = true;
        });

        return $called;
    }

    private function makeJob(): object
    {
        return new class($this->experiment->id)
        {
            public string $experimentId;

            public function __construct(string $id)
            {
                $this->experimentId = $id;
            }
        };
    }

    public function test_allows_job_when_no_ledger_entries_exist(): void
    {
        // Community edition: no credits ever deposited — jobs must not be blocked
        $called = $this->runMiddleware($this->makeJob());

        $this->assertTrue($called, 'Job should proceed when no credit ledger entries exist (community install)');
    }

    public function test_blocks_job_when_balance_is_zero(): void
    {
        // Establish a purchase so the billing guard is active, then drain to zero.
        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $this->experiment->user_id,
            'type' => 'purchase',
            'amount' => 10,
            'balance_after' => 10,
            'description' => 'Initial top-up',
        ]);
        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $this->experiment->user_id,
            'experiment_id' => $this->experiment->id,
            'type' => 'deduction',
            'amount' => 10,
            'balance_after' => 0,
            'description' => 'Zero balance',
        ]);

        $called = $this->runMiddleware($this->makeJob());

        $this->assertFalse($called, 'Job should be blocked when balance is 0');
    }

    public function test_blocks_job_when_balance_is_negative(): void
    {
        // Establish a purchase so the billing guard is active, then overdraft.
        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $this->experiment->user_id,
            'type' => 'purchase',
            'amount' => 5,
            'balance_after' => 5,
            'description' => 'Initial top-up',
        ]);
        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $this->experiment->user_id,
            'experiment_id' => $this->experiment->id,
            'type' => 'deduction',
            'amount' => 10,
            'balance_after' => -5,
            'description' => 'Overdraft',
        ]);

        $called = $this->runMiddleware($this->makeJob());

        $this->assertFalse($called, 'Job should be blocked when balance is negative');
    }

    public function test_allows_job_when_balance_is_positive(): void
    {
        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $this->experiment->user_id,
            'experiment_id' => $this->experiment->id,
            'type' => 'purchase',
            'amount' => 100,
            'balance_after' => 100,
            'description' => 'Top-up',
        ]);

        $called = $this->runMiddleware($this->makeJob());

        $this->assertTrue($called, 'Job should proceed when balance is positive');
    }

    public function test_allows_job_with_no_experiment_id(): void
    {
        $job = new class
        {
            public string $someOtherProp = 'value';
        };

        $called = $this->runMiddleware($job);

        $this->assertTrue($called, 'Job without experimentId property should always proceed');
    }

    public function test_blocks_job_when_experiment_budget_cap_exceeded(): void
    {
        $this->experiment->update([
            'budget_cap_credits' => 50,
            'budget_spent_credits' => 60,
        ]);

        $called = $this->runMiddleware($this->makeJob());

        $this->assertFalse($called, 'Job should be blocked when experiment budget cap is exceeded');
    }
}
