<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\BugReportProjectConfig;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
#[AssistantTool('write')]
class BugReportProjectConfigTool extends Tool
{
    protected string $name = 'bug_report_project_config';

    protected string $description = 'Get or update agent processing instructions for a bug report project. Pass config to update, omit to retrieve the current config.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('The project key (e.g. "myapp")')
                ->required(),
            'config' => $schema->object()
                ->description('Configuration object to save. Omit to only retrieve the current config.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'project' => 'required|string|max:255',
            'config' => 'sometimes|array',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        if (isset($validated['config'])) {
            $record = BugReportProjectConfig::updateOrCreate(
                ['team_id' => $teamId, 'project' => $validated['project']],
                ['config' => $validated['config']],
            );

            return Response::text(json_encode([
                'success' => true,
                'project' => $record->project,
                'config' => $record->config,
            ]));
        }

        $record = BugReportProjectConfig::where('team_id', $teamId)
            ->where('project', $validated['project'])
            ->first();

        return Response::text(json_encode([
            'project' => $validated['project'],
            'config' => $record?->config ?? [],
        ]));
    }
}
