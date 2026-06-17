<?php

namespace App\Mcp\Tools\Memory;

use App\Domain\Memory\Actions\ForgetMemoryAction;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class MemoryForgetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'memory_forget';

    protected string $description = 'GDPR right-to-forget: irreversibly erase the current team\'s memories and all derived projections (knowledge-graph entities, edges, communities, and semantic cache) in one transaction, recording an audit row. Scope to an agent or project to erase only those memories (KG/cache are only purged on a full team erasure).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Restrict erasure to a single agent\'s memories.'),
            'project_id' => $schema->string()
                ->description('Restrict erasure to a single project\'s memories.'),
            'reason' => $schema->string()
                ->description('Why the data is being erased (e.g. gdpr_erasure, manual). Defaults to gdpr_erasure.'),
        ];
    }

    public function handle(Request $request, ForgetMemoryAction $action): Response
    {
        $user = Auth::user();
        $teamId = app('mcp.team_id') ?? $user?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $counts = $action->execute(
            teamId: $teamId,
            agentId: $request->get('agent_id'),
            projectId: $request->get('project_id'),
            reason: $request->get('reason', 'gdpr_erasure'),
        );

        return Response::text(json_encode([
            'success' => true,
            'purged_counts' => $counts,
            'message' => 'Memory and derived projections erased.',
        ]));
    }
}
