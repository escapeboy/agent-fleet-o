<?php

namespace App\Mcp\Tools\Inbox;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Inbox\Actions\RefineTriageWithLlmAction;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class InboxRefineTriageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'inbox_refine_triage';

    protected string $description = 'Re-score one inbox item with the triage LLM instead of the cheap heuristic. Costs a gateway call and is budget-capped; on cap, provider failure or unparseable output it falls back to the heuristic and says so in `reason`.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'item_id' => $schema->string()
                ->description('The approval, human task, or outbound proposal UUID.')
                ->required(),
            'kind' => $schema->string()
                ->description('Which model the id belongs to.')
                ->enum(['approval', 'human_task', 'proposal'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $kind = $request->get('kind');
        $id = $request->get('item_id');

        $item = $kind === 'proposal'
            ? OutboundProposal::withoutGlobalScopes()->where('team_id', $teamId)->find($id)
            : ApprovalRequest::withoutGlobalScopes()->where('team_id', $teamId)->find($id);

        if (! $item) {
            return $this->notFoundError($kind === 'proposal' ? 'outbound proposal' : 'approval request');
        }

        $verdict = app(RefineTriageWithLlmAction::class)->execute($item);

        return Response::text(json_encode([
            'item_id' => $id,
            'kind' => $kind,
            'score' => round($verdict->score, 3),
            'recommendation' => $verdict->recommendation,
            'reason' => $verdict->reason,
            'from_cache' => $verdict->fromCache,
        ]));
    }
}
