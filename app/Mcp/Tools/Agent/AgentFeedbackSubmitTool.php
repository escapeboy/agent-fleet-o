<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\CreateAgentFeedbackAction;
use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentFeedbackSubmitTool extends Tool
{
    protected string $name = 'agent_feedback_submit';

    protected string $description = 'Submit feedback (thumbs up/down) for an agent execution. Negative feedback with a correction trains the agent via the evolution pipeline.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'execution_id' => $schema->string()
                ->description('The AgentExecution UUID to rate')
                ->required(),
            'score' => $schema->integer()
                ->description('1 = positive, -1 = negative, 0 = neutral')
                ->required(),
            'comment' => $schema->string()
                ->description('Optional comment explaining the rating'),
            'correction' => $schema->string()
                ->description('Optional correct output (for negative feedback)'),
            'label' => $schema->string()
                ->description('Optional failure category label'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'execution_id' => 'required|string',
            'score' => 'required|integer|in:-1,0,1',
            'comment' => 'nullable|string|max:1000',
            'correction' => 'nullable|string|max:2000',
            'label' => 'nullable|string|max:100',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $execution = $agent->executions()->find($validated['execution_id']);
        if (! $execution) {
            return Response::error('Execution not found for this agent.');
        }

        $rating = FeedbackRating::from((int) $validated['score']);

        $output = $execution->output ? json_encode($execution->output) : null;
        $input = $execution->input ? json_encode($execution->input) : null;

        $feedback = app(CreateAgentFeedbackAction::class)->execute(
            agent: $agent,
            teamId: $agent->team_id,
            rating: $rating,
            comment: $validated['comment'] ?? null,
            correction: $validated['correction'] ?? null,
            outputSnapshot: $output ? mb_substr($output, 0, 2000) : null,
            inputSnapshot: $input ? mb_substr($input, 0, 1000) : null,
            userId: Auth::id(),
            agentExecutionId: $execution->id,
            label: $validated['label'] ?? null,
        );

        return Response::text(json_encode([
            'id' => $feedback->id,
            'score' => $feedback->score,
            'message' => 'Feedback recorded.',
        ]));
    }
}
