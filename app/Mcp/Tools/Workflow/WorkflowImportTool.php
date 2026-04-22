<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Actions\ImportWorkflowAction;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WorkflowImportTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'workflow_import';

    protected string $description = 'Import a workflow from JSON or YAML content. Returns the new workflow ID and any unresolved references.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()
                ->description('The JSON or YAML workflow content to import')
                ->required(),
            'format' => $schema->string()
                ->description('Content format hint: json or yaml (auto-detected if omitted)')
                ->enum(['json', 'yaml']),
            'team_id' => $schema->string()
                ->description('Target team UUID (uses current team if omitted)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'format' => 'sometimes|string|in:json,yaml',
            'team_id' => 'sometimes|string',
        ]);

        $user = auth()->user();

        $teamId = $validated['team_id'] ?? app('mcp.team_id') ?? $user?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No team context available.');
        }

        $action = app(ImportWorkflowAction::class);
        $result = $action->execute(
            data: $validated['content'],
            teamId: $teamId,
            userId: $user->id,
        );

        $workflow = $result['workflow'];

        return Response::text(json_encode([
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'status' => 'draft',
            'checksum_valid' => $result['checksum_valid'],
            'unresolved_references' => $result['unresolved_references'],
        ]));
    }
}
