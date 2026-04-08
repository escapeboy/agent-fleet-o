<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\ImportAgentWorkspaceAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentWorkspaceImportTool extends Tool
{
    protected string $name = 'agent_workspace_import';

    protected string $description = 'Import an agent workspace from a file path (zip or yaml). Creates a new agent or merges into an existing one.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_path' => $schema->string()
                ->description('Absolute path to the workspace file (.zip or .yaml)')
                ->required(),
            'mode' => $schema->string()
                ->description('Import mode: create (new agent) or merge (into existing agent)')
                ->enum(['create', 'merge'])
                ->default('create'),
            'agent_id' => $schema->string()
                ->description('Agent UUID to merge into (required when mode is merge)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'file_path' => 'required|string',
            'mode' => 'nullable|string|in:create,merge',
            'agent_id' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $filePath = $validated['file_path'];
        if (! file_exists($filePath)) {
            return Response::error("File not found: {$filePath}");
        }

        $mode = $validated['mode'] ?? 'create';
        if ($mode === 'merge' && empty($validated['agent_id'])) {
            return Response::error('agent_id is required when mode is merge.');
        }

        try {
            $result = app(ImportAgentWorkspaceAction::class)->executeFromPath(
                filePath: $filePath,
                teamId: $teamId,
                mode: $mode,
                mergeAgentId: $validated['agent_id'] ?? null,
            );

            return Response::text(json_encode(array_merge(['success' => true], $result)));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
