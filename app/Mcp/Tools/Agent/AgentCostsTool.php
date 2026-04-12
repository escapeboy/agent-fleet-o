<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Budget\Models\CreditLedger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AgentCostsTool extends Tool
{
    protected string $name = 'agent_costs';

    protected string $description = 'Get detailed cost breakdown for a specific agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('The agent ID.')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $agentId = $request->get('agent_id');

        $totalCredits = AiRun::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_id', $agentId)
            ->sum('cost_credits');

        $byType = CreditLedger::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('agent_id', $agentId)
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->type => $row->total])
            ->toArray();

        return Response::text(json_encode([
            'agent_id' => $agentId,
            'total_cost_credits' => $totalCredits,
            'ledger_by_type' => $byType,
        ]));
    }
}
