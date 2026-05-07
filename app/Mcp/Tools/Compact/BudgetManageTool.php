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

    protected string $description = <<<'TXT'
Team-wide credit budget overview, pre-flight cost guards, and spend forecasting. Read-only — does not move money. 1 credit ≈ $0.001 USD; balances and reservations track LLM + outbound + compute spend with pessimistic locking. Use `check` before dispatching any expensive job to avoid mid-run pause-on-budget-exhausted events.

Actions:
- summary (read) — current balance, pending reservations, MTD spend by category (llm/outbound/compute).
- check (read) — estimated_cost (credits). Returns pass/fail without reserving funds.
- forecast (read) — period (week|month|quarter), granularity (day|week). Projects spend by extrapolating recent ledger entries.
TXT;

    protected function toolMap(): array
    {
        return [
            'summary' => BudgetSummaryTool::class,
            'check' => BudgetCheckTool::class,
            'forecast' => BudgetForecastTool::class,
        ];
    }
}
