<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Services\CrewDecisionContext;
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
class CrewDecisionListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_decision_list';

    protected string $description = 'List the durable decisions recorded for a crew (the shared brain), oldest first — the constraints future crew runs inherit.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->required()->description('The crew UUID.'),
        ];
    }

    public function handle(Request $request, CrewDecisionContext $decisions): Response
    {
        $validated = $request->validate(['crew_id' => 'required|string']);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $crew = Crew::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['crew_id']);

        if (! $crew) {
            return $this->notFoundError('crew');
        }

        $rows = $decisions->for((string) $teamId, $crew->id)->map(fn ($m) => [
            'id' => $m->id,
            'decision' => $m->content,
            'why_it_matters' => $m->why_it_matters,
            'recorded_at' => $m->created_at?->toIso8601String(),
        ])->values();

        return Response::text(json_encode([
            'crew_id' => $crew->id,
            'count' => $rows->count(),
            'decisions' => $rows,
        ]));
    }
}
