<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Domain\Tool\Services\ToolDefinitionPinner;
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
class ToolDefinitionChangesTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'tool_definition_changes';

    protected string $description = 'List MCP tools whose server changed its tool definitions after they were approved (definition pinning). Cloud-provider agents keep the approved definitions and local agents do not get the server until a person approves the change. Returns the diff, the new descriptions and the action proposal id; approve or reject it with the action proposal tools.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('Optional tool UUID to limit the result to one tool'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['tool_id' => 'nullable|string']);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $tools = ToolModel::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->whereNotNull('pending_definitions_hash')
            ->when($validated['tool_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->get();

        $changes = $tools->map(function (ToolModel $tool) use ($teamId) {
            $proposal = ActionProposal::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('target_type', ToolDefinitionPinner::TARGET_TYPE)
                ->where('target_id', $tool->id)
                ->where('payload->pending_hash', $tool->pending_definitions_hash)
                ->latest('created_at')
                ->first();

            return [
                'tool_id' => $tool->id,
                'tool_name' => $tool->name,
                'detected_at' => $tool->pending_definitions_detected_at?->toIso8601String(),
                'proposal_id' => $proposal?->id,
                'proposal_status' => $proposal?->getRawOriginal('status'),
                'diff' => $proposal?->payload['diff'] ?? null,
                'new_descriptions' => $proposal?->payload['new_descriptions'] ?? null,
            ];
        })->values();

        return Response::text(json_encode([
            'pinning_enabled' => (bool) config('tools.definition_pinning.enabled', false),
            'count' => $changes->count(),
            'changes' => $changes,
        ]));
    }
}
