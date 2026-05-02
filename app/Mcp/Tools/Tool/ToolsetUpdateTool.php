<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\UpdateToolsetAction;
use App\Domain\Tool\Models\Toolset;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ToolsetUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'toolset_update';

    protected string $description = 'Update an existing toolset name, description, tool list, or tags.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'toolset_id' => $schema->string()
                ->description('The toolset UUID')
                ->required(),
            'name' => $schema->string()
                ->description('New name'),
            'description' => $schema->string()
                ->description('New description'),
            'tool_ids' => $schema->array()
                ->description('Replacement tool UUIDs'),
            'tags' => $schema->array()
                ->description('Replacement tags'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $toolset = Toolset::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('toolset_id'));

        if (! $toolset) {
            return $this->notFoundError('toolset');
        }

        $data = array_filter([
            'name' => $request->get('name'),
            'description' => $request->get('description'),
            'tool_ids' => $request->get('tool_ids'),
            'tags' => $request->get('tags'),
        ], fn ($v) => $v !== null);

        $toolset = app(UpdateToolsetAction::class)->execute($toolset, $data);

        return Response::text(json_encode([
            'id' => $toolset->id,
            'name' => $toolset->name,
            'updated' => true,
        ]));
    }
}
