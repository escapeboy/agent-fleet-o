<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\ImportWorkflowAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * Build #5 (Trendshift top-5 sprint): import a workflow from YAML/JSON.
 */
#[IsDestructive]
#[AssistantTool('write')]
class WorkflowImportYamlTool extends Tool
{
    protected string $name = 'workflow_import_yaml';

    protected string $description = 'Import a workflow from a YAML or JSON envelope (as produced by workflow_export_yaml). Returns the new workflow id, checksum-validity flag, and any unresolved agent/skill/crew references.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'yaml_content' => $schema->string()
                ->required()
                ->description('Full YAML or JSON workflow envelope.'),
            'name_override' => $schema->string()
                ->description('Optional name to use instead of the one in the envelope.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'no_team_resolved']));
        }

        $userId = auth()->id() ?? Team::ownerIdFor((string) $teamId);
        if (! $userId) {
            return Response::text(json_encode(['error' => 'no_user_resolved']));
        }

        $validated = $request->validate([
            'yaml_content' => 'required|string|max:5000000',
            'name_override' => 'nullable|string|max:200',
        ]);

        $result = app(ImportWorkflowAction::class)->execute(
            data: $validated['yaml_content'],
            teamId: (string) $teamId,
            userId: (string) $userId,
            nameOverride: $validated['name_override'] ?? null,
        );

        return Response::text(json_encode([
            'workflow_id' => $result['workflow']->id,
            'unresolved_references' => $result['unresolved_references'],
            'checksum_valid' => $result['checksum_valid'],
        ]));
    }
}
