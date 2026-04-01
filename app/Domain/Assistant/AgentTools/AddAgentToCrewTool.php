<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class AddAgentToCrewTool implements Tool
{
    public function name(): string
    {
        return 'add_agent_to_crew';
    }

    public function description(): string
    {
        return 'Add one or more worker agents to an existing crew. Existing workers are preserved unless replaced.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->required()->description('The crew UUID'),
            'agent_id' => $schema->string()->required()->description('UUID of the agent to add as a worker'),
        ];
    }

    public function handle(Request $request): string
    {
        $crew = Crew::find($request->get('crew_id'));
        if (! $crew) {
            return json_encode(['error' => 'Crew not found']);
        }

        $agentId = $request->get('agent_id');
        $agentExists = Agent::where('id', $agentId)->exists();
        if (! $agentExists) {
            return json_encode(['error' => 'Agent not found']);
        }

        try {
            $existingWorkerIds = $crew->members()
                ->where('role', CrewMemberRole::Worker->value)
                ->pluck('agent_id')
                ->toArray();

            $workerIds = array_unique(array_merge($existingWorkerIds, [$agentId]));

            app(UpdateCrewAction::class)->execute(
                crew: $crew,
                workerAgentIds: $workerIds,
            );

            return json_encode([
                'success' => true,
                'crew_id' => $crew->id,
                'crew_name' => $crew->name,
                'worker_count' => count($workerIds),
                'url' => route('crews.show', $crew),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
