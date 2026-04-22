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
class BudgetLedgerTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'budget_ledger';

    protected string $description = 'Get detailed credit ledger entries for the team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->description('Maximum number of entries to return (default 50).')->default(50),
            'agent_id' => $schema->string()->description('Filter by agent ID.'),
            'experiment_id' => $schema->string()->description('Filter by experiment ID.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $limit = min((int) ($request->get('limit', 50)), 200);

        $query = CreditLedger::withoutGlobalScopes()->where('team_id', $teamId);

        if ($agentId = $request->get('agent_id')) {
            $query->where('agent_id', $agentId);
        }
        if ($experimentId = $request->get('experiment_id')) {
            $query->where('experiment_id', $experimentId);
        }

        $entries = $query->latest()->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $entries->count(),
            'entries' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type->value,
                'amount' => $e->amount,
                'agent_id' => $e->agent_id,
                'experiment_id' => $e->experiment_id,
                'created_at' => $e->created_at,
            ])->toArray(),
        ]));
    }
}
