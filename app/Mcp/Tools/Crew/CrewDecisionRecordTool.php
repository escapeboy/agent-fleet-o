<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\RecordCrewDecisionAction;
use App\Domain\Crew\Models\Crew;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewDecisionRecordTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_decision_record';

    protected string $description = 'Record a durable crew decision (the shared brain). Decisions are append-only, human-readable constraints that future crew runs inherit — recorded as memory (tier=decisions). Injected into the coordinator prompt when crew.decision_log.enabled is on.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->required()->description('The crew UUID this decision belongs to.'),
            'decision' => $schema->string()->required()->description('The decision statement, e.g. "Use hooks not prompts for security guards".'),
            'why_it_matters' => $schema->string()->description('Optional rationale — why this decision was made.'),
            'project_id' => $schema->string()->description('Optional project UUID to scope the decision to.'),
        ];
    }

    public function handle(Request $request, RecordCrewDecisionAction $action): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
            'decision' => 'required|string',
            'why_it_matters' => 'nullable|string',
            'project_id' => 'nullable|string',
        ]);

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

        $memory = $action->execute(
            teamId: (string) $teamId,
            crewId: $crew->id,
            decision: $validated['decision'],
            whyItMatters: $validated['why_it_matters'] ?? null,
            projectId: $validated['project_id'] ?? null,
            decidedBy: auth()->id(),
        );

        return Response::text(json_encode([
            'success' => true,
            'decision_id' => $memory->id,
            'crew_id' => $crew->id,
        ]));
    }
}
