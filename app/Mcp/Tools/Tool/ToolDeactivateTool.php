<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\DeactivatePlatformToolAction;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ToolDeactivateTool extends Tool
{
    protected string $name = 'tool_deactivate';

    protected string $description = 'Deactivate a platform tool for the current team. The tool will no longer be available to agents on this team.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('The platform tool UUID to deactivate')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate(['tool_id' => 'required|string']);

        $tool = ToolModel::withoutGlobalScopes()->find($validated['tool_id']);

        if (! $tool) {
            return Response::error('Tool not found.');
        }

        if (! $tool->isPlatformTool()) {
            return Response::error('Only platform tools can be deactivated this way.');
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No active team context.');
        }

        app(DeactivatePlatformToolAction::class)->execute($tool, $teamId);

        return Response::text(json_encode([
            'success' => true,
            'tool_id' => $tool->id,
            'team_id' => $teamId,
            'status' => 'disabled',
        ]));
    }
}
