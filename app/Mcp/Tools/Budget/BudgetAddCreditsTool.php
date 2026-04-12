<?php

namespace App\Mcp\Tools\Budget;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class BudgetAddCreditsTool extends Tool
{
    protected string $name = 'budget_add_credits';

    protected string $description = 'Add credits to the team budget (admin only).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'amount' => $schema->number()->description('Number of credits to add.')->required(),
            'note' => $schema->string()->description('Optional note for this credit addition.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $amount = (float) $request->get('amount');
        if ($amount <= 0) {
            return Response::error('Amount must be greater than zero.');
        }

        $entry = CreditLedger::create([
            'team_id' => $teamId,
            'type' => LedgerType::Purchase,
            'amount' => $amount,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'id' => $entry->id,
            'amount' => $entry->amount,
            'type' => $entry->type->value,
        ]));
    }
}
