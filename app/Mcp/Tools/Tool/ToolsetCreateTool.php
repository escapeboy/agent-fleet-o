<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\CreateToolsetAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ToolsetCreateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'toolset_create';

    protected string $description = 'Create a new named toolset grouping tools for agents.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Toolset name')
                ->required(),
            'description' => $schema->string()
                ->description('What this toolset is for'),
            'tool_ids' => $schema->array()
                ->description('Array of tool UUIDs to include'),
            'tags' => $schema->array()
                ->description('Tags for categorisation'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'tool_ids' => 'array',
            'tool_ids.*' => 'uuid',
            'tags' => 'array',
        ]);

        $toolset = app(CreateToolsetAction::class)->execute(
            teamId: $teamId,
            name: $validated['name'],
            description: $validated['description'] ?? '',
            toolIds: $validated['tool_ids'] ?? [],
            tags: $validated['tags'] ?? [],
        );

        return Response::text(json_encode([
            'id' => $toolset->id,
            'name' => $toolset->name,
            'slug' => $toolset->slug,
            'created' => true,
        ]));
    }
}
