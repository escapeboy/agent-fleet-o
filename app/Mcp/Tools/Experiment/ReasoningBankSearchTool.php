<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Services\ReasoningBankService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ReasoningBankSearchTool extends Tool
{
    protected string $name = 'reasoning_bank_search';

    protected string $description = 'Search the reasoning bank for past experiment strategies similar to a given goal. Returns the top-K strategy hints including tool sequences and outcome summaries.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal_text' => $schema->string()
                ->description('The goal or thesis text to search for similar past strategies')
                ->required(),
            'limit' => $schema->integer()
                ->description('Number of hints to return (1–10, default 3)')
                ->default(3),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate(['goal_text' => 'required|string|max:2000']);

        $teamId = app('mcp.team_id');
        $limit = min(max((int) $request->get('limit', 3), 1), 10);

        $service = app(ReasoningBankService::class);
        $hints = $service->fetchHints($request->get('goal_text'), $teamId, $limit);

        return Response::text(json_encode([
            'count' => $hints->count(),
            'hints' => $hints->map(fn ($entry) => [
                'id' => $entry->id,
                'goal_text' => $entry->goal_text,
                'outcome_summary' => $entry->outcome_summary,
                'tool_sequence' => $entry->tool_sequence,
                'experiment_id' => $entry->experiment_id,
                'recorded_at' => $entry->created_at?->diffForHumans(),
            ])->values()->toArray(),
        ]));
    }
}
