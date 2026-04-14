<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\Tool as ToolModel;
use App\Domain\Tool\Models\ToolFederationGroup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use App\Mcp\Attributes\AssistantTool;

#[IsDestructive]
#[AssistantTool('write')]
class ToolFederationGroupCreateTool extends Tool
{
    protected string $name = 'tool_federation_group_create';

    protected string $description = 'Create a new tool federation group with a curated set of tools. Agents can be configured to use this group instead of the full team tool pool.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Group name (e.g. "Web Research Stack")'),
            'description' => $schema->string()->description('What this group is for'),
            'tool_ids' => $schema->array()->description('Array of Tool IDs to include in this group'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = current_team()->id;
        $toolIds = $request->get('tool_ids', []);

        // Validate tool IDs belong to this team
        $validIds = ToolModel::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $toolIds)
            ->pluck('id')
            ->toArray();

        $group = ToolFederationGroup::create([
            'team_id' => $teamId,
            'name' => $request->get('name'),
            'description' => $request->get('description'),
            'tool_ids' => $validIds,
            'is_active' => true,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'group_id' => $group->id,
            'name' => $group->name,
            'tool_count' => count($validIds),
        ]));
    }
}
