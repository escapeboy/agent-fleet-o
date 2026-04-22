<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BudgetTransferTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'budget_transfer';

    protected string $description = 'Reserve credits for a specific agent or experiment by creating a debit/reservation entry in the credit ledger.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('Number of credits to reserve.')->required(),
            'to_agent_id' => $schema->string()->description('Agent ID to reserve credits for.'),
            'to_experiment_id' => $schema->string()->description('Experiment ID to reserve credits for.'),
            'note' => $schema->string()->description('Optional note for this transfer.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $amount = (float) $request->get('amount');
        if ($amount <= 0) {
            return $this->invalidArgumentError('Amount must be greater than zero.');
        }

        $entry = DB::transaction(function () use ($teamId, $amount, $request) {
            return CreditLedger::create([
                'team_id' => $teamId,
                'type' => LedgerType::Reservation,
                'amount' => -$amount,
                'agent_id' => $request->get('to_agent_id'),
                'experiment_id' => $request->get('to_experiment_id'),
            ]);
        });

        return Response::text(json_encode([
            'success' => true,
            'id' => $entry->id,
            'amount' => $entry->amount,
            'type' => $entry->type->value,
            'agent_id' => $entry->agent_id,
            'experiment_id' => $entry->experiment_id,
        ]));
    }
}
