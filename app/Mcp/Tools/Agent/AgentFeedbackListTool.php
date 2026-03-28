<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentFeedback;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class AgentFeedbackListTool extends Tool
{
    protected string $name = 'agent_feedback_list';

    protected string $description = 'List recent feedback entries for an agent. Useful for understanding what users liked or disliked about the agent\'s outputs.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'score' => $schema->integer()
                ->description('Filter by score: 1 (positive), -1 (negative), 0 (neutral). Omit to return all.'),
            'limit' => $schema->integer()
                ->description('Max entries to return (default 20, max 100)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'score' => 'nullable|integer|in:-1,0,1',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $query = AgentFeedback::where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit($validated['limit'] ?? 20);

        if (isset($validated['score'])) {
            $query->where('score', (int) $validated['score']);
        }

        $items = $query->get()->map(fn ($fb) => [
            'id' => $fb->id,
            'score' => $fb->score,
            'label' => $fb->label,
            'comment' => $fb->comment,
            'correction' => $fb->correction,
            'created_at' => $fb->created_at->toIso8601String(),
        ]);

        return Response::text(json_encode(['data' => $items, 'total' => $items->count()]));
    }
}
