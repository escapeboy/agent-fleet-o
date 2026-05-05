<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use App\Domain\Evolution\Actions\ProposeCrewRestructuringAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class CrewProposeRestructuringTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'crew_propose_restructuring';

    protected string $description = 'Analyse a crew\'s execution history and propose structural changes. Creates an approval request for human review.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
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

        $userId = auth()->id() ?? '';

        try {
            $approval = app(ProposeCrewRestructuringAction::class)->execute(
                crew: $crew,
                userId: $userId,
            );

            return Response::text(json_encode([
                'success' => true,
                'approval_request_id' => $approval->id,
                'status' => $approval->status->value,
                'crew_id' => $crew->id,
                'crew_name' => $crew->name,
                'summary' => $approval->context['summary'] ?? null,
                'confidence' => $approval->context['confidence'] ?? null,
                'proposed_changes_count' => count($approval->context['proposed_changes'] ?? []),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
