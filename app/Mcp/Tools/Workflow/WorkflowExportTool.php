<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\ExportWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WorkflowExportTool extends Tool
{
    protected string $name = 'workflow_export';

    protected string $description = 'Export a workflow to portable JSON or YAML format with v2 envelope (checksum, references, hints).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to export')
                ->required(),
            'format' => $schema->string()
                ->description('Output format: json or yaml')
                ->enum(['json', 'yaml']),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'format' => 'sometimes|string|in:json,yaml',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $workflow = Workflow::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $format = $validated['format'] ?? 'json';
        $action = app(ExportWorkflowAction::class);
        $result = $action->execute($workflow, $format);

        if ($format === 'yaml') {
            return Response::text($result);
        }

        return Response::text(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
