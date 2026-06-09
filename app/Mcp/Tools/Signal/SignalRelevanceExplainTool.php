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
class SignalRelevanceExplainTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'signal_relevance_explain';

    protected string $description = 'Explain a signal\'s learned relevance score: the overall 0.0-1.0 value plus the per-signal "why ranked here" breakdown (preference similarity, novelty, LLM quality). Learned relevance is the team-personalised score derived from which signals the team Resolved vs Dismissed; it is null until enough labelled history exists.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('The signal UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['signal_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('Team context is required.');
        }

        $signal = Signal::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['signal_id']);

        if (! $signal) {
            return $this->notFoundError('signal');
        }

        return Response::text(json_encode([
            'id' => $signal->id,
            'learned_relevance_score' => $signal->learned_relevance_score,
            'learned_relevance_at' => $signal->learned_relevance_at?->toIso8601String(),
            'breakdown' => $signal->metadata['learned_relevance'] ?? null,
            'novelty' => $signal->metadata['novelty'] ?? null,
            'llm_relevance_score' => $signal->relevance_score,
            'scored' => $signal->learned_relevance_score !== null,
        ]));
    }
}
