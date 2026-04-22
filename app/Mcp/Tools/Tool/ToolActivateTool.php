<?php

namespace App\Mcp\Tools\Tool;

use App\Domain\Tool\Actions\ActivatePlatformToolAction;
use App\Domain\Tool\Models\Tool as ToolModel;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ToolActivateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'tool_activate';

    protected string $description = 'Activate a platform tool for the current team with optional credential overrides. Only works for platform tools (is_platform = true).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'tool_id' => $schema->string()
                ->description('The platform tool UUID to activate')
                ->required(),
            'credential_overrides' => $schema->object()
                ->description('Key-value pairs of environment variable overrides (e.g. API keys). Values are stored encrypted.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'tool_id' => 'required|string',
            'credential_overrides' => 'array',
        ]);

        $tool = ToolModel::withoutGlobalScopes()->find($validated['tool_id']);

        if (! $tool) {
            return $this->notFoundError('tool');
        }

        if (! $tool->isPlatformTool()) {
            return $this->failedPreconditionError('Only platform tools can be activated this way.');
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No active team context.');
        }

        $activation = app(ActivatePlatformToolAction::class)->execute(
            $tool,
            $teamId,
            $validated['credential_overrides'] ?? [],
        );

        return Response::text(json_encode([
            'success' => true,
            'tool_id' => $tool->id,
            'team_id' => $teamId,
            'status' => $activation->status,
            'activated_at' => $activation->activated_at?->toIso8601String(),
        ]));
    }
}
