<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\ExportWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Build #5 (Trendshift top-5 sprint): Kestra-inspired YAML export of a workflow.
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class WorkflowExportYamlTool extends Tool
{
    protected string $name = 'workflow_export_yaml';

    protected string $description = 'Export a workflow as portable YAML (v2 envelope with checksum + fuzzy reference hints). Returns the YAML string ready to commit to a repository or paste into a prompt.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->required()
                ->description('UUID of the workflow to export — must belong to the current team.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'no_team_resolved']));
        }

        $validated = $request->validate([
            'workflow_id' => "required|uuid|exists:workflows,id,team_id,{$teamId}",
        ]);

        $workflow = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->findOrFail($validated['workflow_id']);

        $yaml = app(ExportWorkflowAction::class)->execute($workflow, format: 'yaml');

        return Response::text(is_string($yaml) ? $yaml : json_encode($yaml));
    }
}
