<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Budget\BudgetCheckTool;
use App\Mcp\Tools\Budget\BudgetForecastTool;
use App\Mcp\Tools\Budget\BudgetSummaryTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BudgetManageTool extends CompactTool
{
    protected string $name = 'budget_manage';

    protected string $description = 'Manage team budget and costs. Actions: summary (get budget overview), check (check if budget allows operation, estimated_cost), forecast (period, granularity).';

    protected function toolMap(): array
    {
        return [
            'summary' => BudgetSummaryTool::class,
            'check' => BudgetCheckTool::class,
            'forecast' => BudgetForecastTool::class,
        ];
    }
}
