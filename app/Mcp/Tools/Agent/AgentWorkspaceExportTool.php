<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\ExportAgentWorkspaceAction;
use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class AgentWorkspaceExportTool extends Tool
{
    protected string $name = 'agent_workspace_export';

    protected string $description = 'Export an agent\'s full workspace (identity, tools, memories, soul.md) as a zip or yaml file. Returns the file path for download.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID to export')
                ->required(),
            'format' => $schema->string()
                ->description('Export format: zip or yaml (default: zip)')
                ->enum(['zip', 'yaml'])
                ->default('zip'),
            'include_memories' => $schema->boolean()
                ->description('Whether to include agent memories in export (default: true)')
                ->default(true),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'format' => 'nullable|string|in:zip,yaml',
            'include_memories' => 'nullable|boolean',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return Response::error('Agent not found.');
        }

        try {
            $path = app(ExportAgentWorkspaceAction::class)->execute(
                agent: $agent,
                format: $validated['format'] ?? 'zip',
                includeMemories: $validated['include_memories'] ?? true,
            );

            return Response::text(json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'agent_name' => $agent->name,
                'format' => $validated['format'] ?? 'zip',
                'file_path' => $path,
                'file_name' => basename($path),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
