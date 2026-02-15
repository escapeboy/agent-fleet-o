<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Models\Crew;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CrewUpdateTool extends Tool
{
    protected string $name = 'crew_update';

    protected string $description = 'Update an existing crew. Only provided fields will be changed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New crew name'),
            'description' => $schema->string()
                ->description('New crew description'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
        ]);

        $crew = Crew::find($validated['crew_id']);

        if (! $crew) {
            return Response::error('Crew not found.');
        }

        try {
            $result = app(UpdateCrewAction::class)->execute(
                crew: $crew,
                name: $validated['name'] ?? null,
                description: $validated['description'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'crew_id' => $result->id,
                'name' => $result->name,
                'updated_fields' => array_keys(array_filter([
                    'name' => $validated['name'] ?? null,
                    'description' => $validated['description'] ?? null,
                ], fn ($v) => $v !== null)),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
