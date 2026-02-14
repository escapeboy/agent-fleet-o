<?php

namespace Database\Factories\Domain\Budget;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class CreditLedgerFactory extends Factory
{
    protected $model = CreditLedger::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'type' => LedgerType::Deduction,
            'amount' => fake()->numberBetween(10, 500),
            'balance_after' => fake()->numberBetween(1000, 10000),
            'description' => fake()->sentence(),
            'metadata' => [],
        ];
    }

    public function purchase(int $amount = 10000): static
    {
        return $this->state([
            'type' => LedgerType::Purchase,
            'amount' => $amount,
        ]);
    }
}
