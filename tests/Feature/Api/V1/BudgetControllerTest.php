<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Budget\Models\CreditLedger;

class BudgetControllerTest extends ApiTestCase
{
    public function test_can_get_budget(): void
    {
        $this->actingAsApiUser();

        CreditLedger::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => 'purchase',
            'amount' => 1000,
            'balance_after' => 1000,
            'description' => 'Initial credit purchase',
            'metadata' => [],
        ]);

        $response = $this->getJson('/api/v1/budget');

        $response->assertOk()
            ->assertJsonPath('data.balance', 1000)
            ->assertJsonCount(1, 'data.recent_entries');
    }

    public function test_empty_budget_returns_zero(): void
    {
        $this->actingAsApiUser();

        $response = $this->getJson('/api/v1/budget');

        $response->assertOk()
            ->assertJsonPath('data.balance', 0);
    }

    public function test_unauthenticated_cannot_get_budget(): void
    {
        $response = $this->getJson('/api/v1/budget');

        $response->assertStatus(401);
    }
}
