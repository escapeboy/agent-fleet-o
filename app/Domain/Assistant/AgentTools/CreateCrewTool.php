<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateCrewTool implements Tool
{
    public function name(): string
    {
        return 'create_crew';
    }

    public function description(): string
    {
        return 'Create a new crew (multi-agent team). Requires a coordinator agent and a QA agent.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Crew name'),
            'coordinator_agent_id' => $schema->string()->required()->description('UUID of the coordinator agent'),
            'qa_agent_id' => $schema->string()->required()->description('UUID of the QA agent (must be different from coordinator)'),
            'description' => $schema->string()->description('Crew description'),
            'process_type' => $schema->string()->description('Process type: sequential, parallel, hierarchical (default: hierarchical)'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $processType = CrewProcessType::tryFrom($request->get('process_type', '')) ?? CrewProcessType::Hierarchical;

            $crew = app(CreateCrewAction::class)->execute(
                userId: auth()->id(),
                name: $request->get('name'),
                coordinatorAgentId: $request->get('coordinator_agent_id'),
                qaAgentId: $request->get('qa_agent_id'),
                description: $request->get('description'),
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
    }
}
