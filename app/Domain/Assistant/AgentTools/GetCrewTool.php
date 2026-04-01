<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetCrewTool implements Tool
{
    public function name(): string
    {
        return 'get_crew';
    }

    public function description(): string
    {
        return 'Get detailed information about a specific crew';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()->required()->description('The crew UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $crew = Crew::with('members.agent')->find($request->get('crew_id'));
        if (! $crew) {
            return json_encode(['error' => 'Crew not found']);
        }

        return json_encode([
            'id' => $crew->id,
            'name' => $crew->name,
            'status' => $crew->status->value,
            'process_type' => $crew->process_type->value,
            'members' => $crew->members->map(fn ($m) => [
                'role' => $m->role->value,
                'agent_name' => $m->agent?->name,
            ])->toArray(),
            'url' => route('crews.show', $crew),
        ]);
    }
}
