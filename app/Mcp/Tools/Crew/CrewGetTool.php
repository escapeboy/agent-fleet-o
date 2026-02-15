<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class CrewGetTool extends Tool
{
    protected string $name = 'crew_get';

    protected string $description = 'Get detailed information about a specific crew including members and their agents.';

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
        $validated = $request->validate(['crew_id' => 'required|string']);

        $crew = Crew::with('members.agent')->find($validated['crew_id']);

        if (! $crew) {
            return Response::error('Crew not found.');
        }

        return Response::text(json_encode([
            'id' => $crew->id,
            'name' => $crew->name,
            'status' => $crew->status->value,
            'process_type' => $crew->process_type->value,
            'description' => $crew->description,
            'members' => $crew->members->map(fn ($m) => [
                'role' => $m->role->value,
                'agent_name' => $m->agent?->name,
            ])->toArray(),
            'created_at' => $crew->created_at?->toIso8601String(),
        ]));
    }
}
