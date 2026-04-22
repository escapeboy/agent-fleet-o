<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Domain\Tool\Models\ToolFederationGroup;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class ToolPoolListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'tool_pool_list';

    protected string $description = "List all active tools available in the team's federation pool. Optionally filter by a federation group.";

    public function schema(JsonSchema $schema): array
    {
        return [
            'federation_group_id' => $schema->string()
                ->description('Optional: filter tools to those in a specific federation group'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $query = ToolModel::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('status', ToolStatus::Active)
            ->orderBy('name');

        if ($groupId = $request->get('federation_group_id')) {
            $group = ToolFederationGroup::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->find($groupId);
            if ($group && ! empty($group->tool_ids)) {
                $query->whereIn('id', $group->tool_ids);
            }
        }

        $tools = $query->get();

        return Response::text(json_encode([
            'count' => $tools->count(),
            'tools' => $tools->map(fn (ToolModel $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'type' => $t->type->value,
                'description' => $t->description,
                'tool_count' => $t->functionCount(),
                'health_status' => $t->health_status,
                'last_health_check' => $t->last_health_check?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
