<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Agent\Services\WorkspaceContractWriter;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class AgentWorkspaceContractGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_workspace_contract_get';

    protected string $description = 'Fetch the AGENTS.md / feature-list.json / progress.md / init.sh workspace contract for an AgentExecution. Returns the persisted snapshot, building one on the fly if none exists yet. The contract is the durable handoff a long-running agent reads on each wake.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'execution_id' => $schema->string()
                ->description('AgentExecution UUID')
                ->required(),
            'rebuild' => $schema->boolean()
                ->description('Force a rebuild from current workflow/project state instead of reading the persisted snapshot.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'execution_id' => 'required|string',
            'rebuild' => 'nullable|boolean',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $execution = AgentExecution::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['execution_id']);
        if (! $execution) {
            return $this->notFoundError('agent_execution');
        }

        $writer = app(WorkspaceContractWriter::class);
        $snapshot = ! empty($validated['rebuild'])
            ? $writer->prepare($execution)
            : $writer->restoreOrPrepare($execution);

        return Response::json([
            'execution_id' => $execution->id,
            'snapshot' => $snapshot->toArray(),
        ]);
    }
}
