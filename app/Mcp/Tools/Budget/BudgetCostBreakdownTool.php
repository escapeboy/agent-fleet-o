<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Models\CreditLedger;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class BudgetCostBreakdownTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'budget_cost_breakdown';

    protected string $description = 'Get cost breakdown by agent for the team over a given number of days.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->description('Number of days to look back (default 30).')->default(30),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $days = max(1, (int) ($request->get('days', 30)));
        $since = now()->subDays($days);

        $byAgent = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $since)
            ->selectRaw('agent_id, SUM(ABS(amount)) as total_credits')
            ->groupBy('agent_id')
            ->orderByDesc('total_credits')
            ->get()
            ->map(fn ($row) => [
                'agent_id' => $row->agent_id,
                'total_credits' => $row->total_credits,
            ])
            ->toArray();

        $byType = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('created_at', '>=', $since)
            ->selectRaw('type, SUM(ABS(amount)) as total_credits')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->type => $row->total_credits])
            ->toArray();

        return Response::text(json_encode([
            'period_days' => $days,
            'by_agent' => $byAgent,
            'by_type' => $byType,
        ]));
    }
}
