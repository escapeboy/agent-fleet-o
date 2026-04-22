<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Enums\FeedbackRating;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentFeedback;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class AgentFeedbackStatsTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_feedback_stats';

    protected string $description = 'Get aggregated feedback statistics for an agent: total ratings, satisfaction score, top failure categories.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'days' => $schema->integer()
                ->description('Lookback period in days (default 30)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'days' => 'nullable|integer|min:1|max:365',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $days = (int) ($validated['days'] ?? 30);

        $feedback = AgentFeedback::where('agent_id', $agent->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get();

        $positive = $feedback->where('score', FeedbackRating::Positive->value)->count();
        $negative = $feedback->where('score', FeedbackRating::Negative->value)->count();
        $neutral = $feedback->where('score', FeedbackRating::Neutral->value)->count();
        $total = $feedback->count();

        $satisfactionPct = $total > 0 ? round(($positive / $total) * 100, 1) : null;

        $topLabels = $feedback
            ->whereNotNull('label')
            ->groupBy('label')
            ->map(fn ($g) => $g->count())
            ->sortDesc()
            ->take(5);

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'period_days' => $days,
            'total' => $total,
            'positive' => $positive,
            'negative' => $negative,
            'neutral' => $neutral,
            'satisfaction_pct' => $satisfactionPct,
            'top_failure_labels' => $topLabels,
        ]));
    }
}
