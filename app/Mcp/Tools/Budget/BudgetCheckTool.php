<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Models\CreditLedger;
use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class BudgetCheckTool extends Tool
{
    protected string $name = 'budget_check';

    protected string $description = 'Check if budget is available. Optionally check if a specific amount of credits is available.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()
                ->description('Amount of credits to check availability for. If not provided, returns general availability.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $globalBudgetCap = (int) GlobalSetting::get('global_budget_cap', 100000);

        $totalSpent = (int) abs(CreditLedger::query()->sum('amount'));

        $remaining = max(0, $globalBudgetCap - $totalSpent);

        $amount = $request->get('amount');
        $available = $amount !== null
            ? $remaining >= (float) $amount
            : $remaining > 0;

        return Response::text(json_encode([
            'available' => $available,
            'remaining' => $remaining,
        ]));
    }
}
