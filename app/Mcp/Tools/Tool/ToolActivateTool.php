<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\ActivatePlatformToolAction;
use App\Domain\Tool\Models\Tool as ToolModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ToolActivateTool extends Tool
{
    protected string $name = 'tool_activate';

    protected string $description = 'Activate a platform tool for the current team with optional credential overrides. Only works for platform tools (is_platform = true).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('The platform tool UUID to activate')
                ->required(),
            'credential_overrides' => $schema->object()
                ->description('Key-value pairs of environment variable overrides (e.g. API keys). Values are stored encrypted.')
                ->additionalProperties($schema->string()),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id'              => 'required|string',
            'credential_overrides' => 'array',
        ]);

        $tool = ToolModel::withoutGlobalScopes()->find($validated['tool_id']);

        if (! $tool) {
            return Response::error('Tool not found.');
        }

        if (! $tool->isPlatformTool()) {
            return Response::error('Only platform tools can be activated this way.');
        }

        $teamId = auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No active team context.');
        }

        $activation = app(ActivatePlatformToolAction::class)->execute(
            $tool,
            $teamId,
            $validated['credential_overrides'] ?? [],
        );

        return Response::text(json_encode([
            'success'      => true,
            'tool_id'      => $tool->id,
            'team_id'      => $teamId,
            'status'       => $activation->status,
            'activated_at' => $activation->activated_at?->toIso8601String(),
        ]));
    }
}
