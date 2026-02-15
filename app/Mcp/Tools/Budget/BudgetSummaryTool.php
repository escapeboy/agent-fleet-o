<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Enums\LedgerType;
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
class BudgetSummaryTool extends Tool
{
    protected string $name = 'budget_summary';

    protected string $description = 'Get budget summary including global cap, total spent, total reserved, remaining credits, and utilization percentage.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $globalBudgetCap = (int) GlobalSetting::get('global_budget_cap', 100000);

        $totalSpent = (int) abs(CreditLedger::query()->sum('amount'));

        $totalReserved = (int) abs(
            CreditLedger::query()
                ->where('type', LedgerType::Reservation)
                ->sum('amount'),
        );

        $remaining = max(0, $globalBudgetCap - $totalSpent);
        $utilizationPct = $globalBudgetCap > 0
            ? round(($totalSpent / $globalBudgetCap) * 100, 2)
            : 0;

        return Response::text(json_encode([
            'global_budget_cap' => $globalBudgetCap,
            'total_spent' => $totalSpent,
            'total_reserved' => $totalReserved,
            'remaining' => $remaining,
            'utilization_pct' => $utilizationPct,
        ]));
    }
}
