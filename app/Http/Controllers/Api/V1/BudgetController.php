<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Budget\Models\CreditLedger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $balance = CreditLedger::where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->value('balance_after') ?? 0;

        $recentEntries = CreditLedger::where('team_id', $teamId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'amount' => $entry->amount,
                'balance_after' => $entry->balance_after,
                'description' => $entry->description,
                'experiment_id' => $entry->experiment_id,
                'created_at' => $entry->created_at->toISOString(),
            ]);

        return response()->json([
            'data' => [
                'balance' => $balance,
                'recent_entries' => $recentEntries,
            ],
        ]);
    }
}
