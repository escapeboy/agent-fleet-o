<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CrewCreateTool extends Tool
{
    protected string $name = 'crew_create';

    protected string $description = 'Create a new crew (multi-agent team). Requires a name, coordinator agent, and QA agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Crew name')
                ->required(),
            'coordinator_agent_id' => $schema->string()
                ->description('UUID of the coordinator agent')
                ->required(),
            'qa_agent_id' => $schema->string()
                ->description('UUID of the QA agent')
                ->required(),
            'description' => $schema->string()
                ->description('Crew description'),
            'process_type' => $schema->string()
                ->description('Process type: sequential, parallel, hierarchical (default: hierarchical)')
                ->enum(['sequential', 'parallel', 'hierarchical'])
                ->default('hierarchical'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'coordinator_agent_id' => 'required|string',
            'qa_agent_id' => 'required|string',
            'description' => 'nullable|string',
            'process_type' => 'nullable|string|in:sequential,parallel,hierarchical',
        ]);

        try {
            $crew = app(CreateCrewAction::class)->execute(
                userId: auth()->id(),
                name: $validated['name'],
                coordinatorAgentId: $validated['coordinator_agent_id'],
                qaAgentId: $validated['qa_agent_id'],
                description: $validated['description'] ?? null,
                processType: CrewProcessType::from($validated['process_type'] ?? 'hierarchical'),
                teamId: auth()->user()->current_team_id,
            );

            return Response::text(json_encode([
                'success' => true,
                'crew_id' => $crew->id,
                'name' => $crew->name,
                'status' => $crew->status->value,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
