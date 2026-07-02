<?php

namespace App\Mcp\Tools\AgentSession;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentSession\Actions\CreateAgentSessionAction;
use App\Domain\AgentSession\Enums\AgentSessionStatus;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentSessionStartTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_session_start';

    protected string $description = 'Open a new AgentSession for long-running agent work, optionally tied to an agent, experiment, or crew execution. Returns the session id and status (active). Use sleep()/wake() to survive sandbox restarts and handoff() to transfer to another sandbox. Experiments on agent-driven tracks open a session automatically; use this for standalone agent work.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->description('Optional Agent UUID to attribute the session to'),
            'experiment_id' => $schema->string()->description('Optional Experiment UUID this session belongs to'),
            'crew_execution_id' => $schema->string()->description('Optional CrewExecution UUID this session belongs to'),
            'metadata' => $schema->object()->description('Optional freeform metadata to attach to the session'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'nullable|string',
            'experiment_id' => 'nullable|string',
            'crew_execution_id' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // bound() guard: an absent/null mcp.team_id must degrade to a
        // permission-denied response, not a ReflectionException 500 (the raw
        // app('mcp.team_id') lookup throws when the binding is missing).
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null)
            ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        // Ownership guard: a caller must not attach a session to another team's
        // agent/experiment/crew execution. Reject any referenced id that does
        // not resolve within the caller's team (scope-free + explicit team_id).
        $ownershipError = $this->assertBelongsToTeam($validated, $teamId);
        if ($ownershipError !== null) {
            return $ownershipError;
        }

        $session = app(CreateAgentSessionAction::class)->execute(
            teamId: $teamId,
            agentId: $validated['agent_id'] ?? null,
            experimentId: $validated['experiment_id'] ?? null,
            crewExecutionId: $validated['crew_execution_id'] ?? null,
            userId: auth()->id(),
            metadata: $validated['metadata'] ?? [],
        );

        $session->update([
            'status' => AgentSessionStatus::Active,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
        ]);

        return Response::json([
            'id' => $session->id,
            'status' => $session->status->value,
            'started_at' => $session->started_at?->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function assertBelongsToTeam(array $validated, string $teamId): ?Response
    {
        $refs = [
            'agent_id' => Agent::class,
            'experiment_id' => Experiment::class,
            'crew_execution_id' => CrewExecution::class,
        ];

        foreach ($refs as $field => $model) {
            $id = $validated[$field] ?? null;
            if ($id === null) {
                continue;
            }

            $belongs = $model::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->whereKey($id)
                ->exists();

            if (! $belongs) {
                return $this->notFoundError($field);
            }
        }

        return null;
    }
}
