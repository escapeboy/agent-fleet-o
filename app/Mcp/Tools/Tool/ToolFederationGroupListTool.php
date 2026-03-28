<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Models\ToolFederationGroup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ToolFederationGroupListTool extends Tool
{
    protected string $name = 'tool_federation_group_list';

    protected string $description = 'List all tool federation groups for the current team.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = Auth::user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $groups = ToolFederationGroup::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get();

        return Response::text(json_encode([
            'count' => $groups->count(),
            'groups' => $groups->map(fn (ToolFederationGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'tool_count' => count($g->tool_ids ?? []),
                'is_active' => $g->is_active,
            ])->toArray(),
        ]));
    }
}
