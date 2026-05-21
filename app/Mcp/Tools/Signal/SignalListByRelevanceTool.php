<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\Signal;
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
class SignalListByRelevanceTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'signal_list_by_relevance';

    protected string $description = 'List signals filtered by minimum relevance score, ordered by relevance descending.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'min_score' => $schema->number()
                ->description('Minimum relevance score filter (0.0–1.0)'),
            'limit' => $schema->integer()
                ->description('Maximum signals to return (default: 20, max: 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'min_score' => 'nullable|numeric|min:0|max:1',
            'limit' => 'integer|min:1|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        $limit = $validated['limit'] ?? 20;
        $minScore = isset($validated['min_score']) ? (float) $validated['min_score'] : 0.0;

        $signals = Signal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('relevance_score')
            ->where('relevance_score', '>=', $minScore)
            ->orderByDesc('relevance_score')
            ->limit($limit)
            ->get(['id', 'source_type', 'source_identifier', 'relevance_score', 'relevance_scored_at', 'score', 'status', 'received_at']);

        return Response::text(json_encode([
            'count' => $signals->count(),
            'min_score_filter' => $minScore,
            'signals' => $signals->map(fn (Signal $s) => [
                'id' => $s->id,
                'source_type' => $s->source_type,
                'source_identifier' => $s->source_identifier,
                'relevance_score' => $s->relevance_score,
                'relevance_scored_at' => $s->relevance_scored_at?->toIso8601String(),
                'intent_score' => $s->score,
                'status' => $s->status->value,
                'received_at' => $s->received_at?->toIso8601String(),
            ])->values()->toArray(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
