<?php

namespace App\Mcp\Tools\Experiment;

use App\Domain\Experiment\Actions\PlanWithKnowledgeAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * MCP tool: Enrich a planning goal with three layers of context before decomposition.
 *
 * Layer 1 — Memory: past experiment outcomes relevant to the goal.
 * Layer 2 — KnowledgeGraph: domain facts related to the goal.
 * Layer 3 — First-principles LLM reasoning: insights, risks, and key questions.
 */
#[IsReadOnly]
#[IsIdempotent]
class PlanWithKnowledgeTool extends Tool
{
    protected string $name = 'plan_with_knowledge';

    protected string $description = 'Enrich a planning goal with context from Memory (past experiments), KnowledgeGraph (domain facts), and first-principles LLM analysis. Returns insights, risks, and key questions to inform agent planning.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'goal' => $schema->string()
                ->description('The planning goal or objective to enrich with contextual knowledge')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'goal' => 'required|string|max:2000',
        ]);

        try {
            /** @var PlanWithKnowledgeAction $action */
            $action = app(PlanWithKnowledgeAction::class);

            $teamId = app()->bound('mcp.team_id') ? app('mcp.team_id') : auth()->user()?->current_team_id;
            if (! $teamId) {
                return Response::error('No current team.');
            }

            $result = $action->execute(
                goal: $validated['goal'],
                teamId: $teamId,
            );

            return Response::text(json_encode([
                'goal' => $validated['goal'],
                'memory_hits' => $result['memory_hits'],
                'kg_hits' => $result['kg_hits'],
                'first_principles' => $result['first_principles'],
                'enriched_context' => $result['enriched_context'],
                'memory_count' => count($result['memory_hits']),
                'kg_count' => count($result['kg_hits']),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
