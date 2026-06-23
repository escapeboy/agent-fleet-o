<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Models\Workflow;

/**
 * Materializes a reusable "Deep Research" workflow (borrowed from Onyx's
 * multi-step research flow): Start → Plan → Research → Synthesize → End.
 *
 * Uses `llm` nodes (self-contained prompts, no agent_id required). The Research
 * node carries a `suggested_tool: web_search` hint — wiring it to an agent node
 * with the web_search tool for live grounding is the documented enhancement.
 *
 * Idempotent: returns the existing workflow if one with the same name already
 * exists for the team (no duplicate on re-run).
 */
class BuildDeepResearchWorkflowAction
{
    public function __construct(
        private readonly CreateWorkflowAction $createWorkflow,
    ) {}

    public function execute(string $teamId, string $userId, ?string $name = null): Workflow
    {
        $name ??= config('deep_research.workflow_name', 'Deep Research');

        $existing = Workflow::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $nodes = [
            ['type' => 'start', 'label' => 'Start', 'position_x' => 0, 'position_y' => 0, 'config' => []],
            ['type' => 'llm', 'label' => 'Plan', 'position_x' => 220, 'position_y' => 0, 'config' => [
                'prompt' => "Decompose the research question into 3-5 focused sub-questions.\n\nQuestion: {{input.question}}",
                'output_key' => 'plan',
            ]],
            ['type' => 'llm', 'label' => 'Research', 'position_x' => 440, 'position_y' => 0, 'config' => [
                'prompt' => "For each sub-question, gather facts and cite sources. Prefer fresh information via web search.\n\nPlan: {{plan}}",
                'suggested_tool' => 'web_search',
                'output_key' => 'findings',
            ]],
            ['type' => 'llm', 'label' => 'Synthesize', 'position_x' => 660, 'position_y' => 0, 'config' => [
                'prompt' => "Write a structured research report that answers the question, with inline source citations.\n\nFindings: {{findings}}",
                'output_key' => 'report',
            ]],
            ['type' => 'end', 'label' => 'End', 'position_x' => 880, 'position_y' => 0, 'config' => []],
        ];

        $edges = [
            ['source_node_index' => 0, 'target_node_index' => 1],
            ['source_node_index' => 1, 'target_node_index' => 2],
            ['source_node_index' => 2, 'target_node_index' => 3],
            ['source_node_index' => 3, 'target_node_index' => 4],
        ];

        return $this->createWorkflow->execute(
            userId: $userId,
            name: $name,
            description: 'Multi-step deep-research flow: plan → research (web_search) → synthesize with citations. Borrowed from Onyx deep research.',
            nodes: $nodes,
            edges: $edges,
            teamId: $teamId,
            settings: ['template' => 'deep_research'],
        );
    }
}
