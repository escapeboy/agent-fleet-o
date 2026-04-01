<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Budget\Models\CreditLedger;
use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetBudgetSummaryTool implements Tool
{
    public function name(): string
    {
        return 'get_budget_summary';
    }

    public function description(): string
    {
        return 'Get the current team budget summary including total spent, cap, and remaining credits';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $globalCap = GlobalSetting::get('global_budget_cap', 100000);

        $totalSpent = CreditLedger::sum('amount');
        $totalReserved = CreditLedger::where('type', 'reservation')->sum('amount');

        return json_encode([
            'global_budget_cap' => $globalCap,
            'total_spent' => abs($totalSpent),
            'total_reserved' => abs($totalReserved),
            'remaining' => $globalCap - abs($totalSpent),
            'utilization_pct' => $globalCap > 0 ? round(abs($totalSpent) / $globalCap * 100, 1) : 0,
        ]);
    }
}
