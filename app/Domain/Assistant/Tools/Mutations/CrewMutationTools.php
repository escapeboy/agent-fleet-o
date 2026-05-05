<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Models\Crew;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class CrewMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createCrew(),
            self::addAgentToCrew(),
            self::executeCrew(),
        ];
    }

    public static function createCrew(): PrismToolObject
    {
        return PrismTool::as('create_crew')
            ->for('Create a new crew (multi-agent team). Requires a coordinator agent. The QA agent is optional — when omitted the coordinator reviews their own work (solo-mode crew).')
            ->withStringParameter('name', 'Crew name', required: true)
            ->withStringParameter('coordinator_agent_id', 'UUID of the coordinator agent', required: true)
            ->withStringParameter('qa_agent_id', 'UUID of the QA agent (must differ from coordinator). Optional — omit for a solo-mode crew.')
            ->withStringParameter('description', 'Crew description')
            ->withStringParameter('process_type', 'Process type: sequential, parallel, hierarchical (default: hierarchical)')
            ->using(function (string $name, string $coordinator_agent_id, ?string $qa_agent_id = null, ?string $description = null, ?string $process_type = null) {
                try {
                    $processType = CrewProcessType::tryFrom($process_type ?? '') ?? CrewProcessType::Hierarchical;

                    $crew = app(CreateCrewAction::class)->execute(
                        userId: auth()->id(),
                        name: $name,
                        coordinatorAgentId: $coordinator_agent_id,
                        qaAgentId: $qa_agent_id,
                        description: $description,
                        processType: $processType,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'crew_id' => $crew->id,
                        'name' => $crew->name,
                        'status' => $crew->status->value,
                        'url' => route('crews.show', $crew),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function addAgentToCrew(): PrismToolObject
    {
        return PrismTool::as('add_agent_to_crew')
            ->for('Add one or more worker agents to an existing crew. Existing workers are preserved unless replaced.')
            ->withStringParameter('crew_id', 'The crew UUID', required: true)
            ->withStringParameter('agent_id', 'UUID of the agent to add as a worker', required: true)
            ->using(function (string $crew_id, string $agent_id) {
                $crew = Crew::find($crew_id);
                if (! $crew) {
                    return json_encode(['error' => 'Crew not found']);
                }

                // Verify agent belongs to the same team (TeamScope already guards crew, but agent_id
                // is supplied as a raw UUID from LLM output — must be validated explicitly).
                $agentExists = Agent::where('id', $agent_id)->exists();
                if (! $agentExists) {
                    return json_encode(['error' => 'Agent not found']);
                }

                try {
                    // Merge the new agent into the existing worker list (deduplication included)
                    $existingWorkerIds = $crew->members()
                        ->where('role', CrewMemberRole::Worker->value)
                        ->pluck('agent_id')
                        ->toArray();

                    $workerIds = array_unique(array_merge($existingWorkerIds, [$agent_id]));

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
            });
    }

    public static function executeCrew(): PrismToolObject
    {
        return PrismTool::as('execute_crew')
            ->for('Start a crew execution with a goal. The crew must be active.')
            ->withStringParameter('crew_id', 'The crew UUID', required: true)
            ->withStringParameter('goal', 'The goal or task for the crew to accomplish', required: true)
            ->using(function (string $crew_id, string $goal) {
                $crew = Crew::find($crew_id);
                if (! $crew) {
                    return json_encode(['error' => 'Crew not found']);
                }

                try {
                    $execution = app(ExecuteCrewAction::class)->execute(
                        crew: $crew,
                        goal: $goal,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'execution_id' => $execution->id,
                        'crew_name' => $crew->name,
                        'status' => $execution->status->value,
                        'url' => route('crews.show', $crew),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
