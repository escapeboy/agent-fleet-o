<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\GraphValidator;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class GenerateWorkflowFromPromptAction
{
    public function __construct(
        private readonly CreateWorkflowAction $createWorkflow,
        private readonly GraphValidator $graphValidator,
    ) {}

    /**
     * Generate a workflow from a natural language prompt.
     *
     * Uses an LLM to decompose the prompt into a workflow graph, validates it,
     * and creates the workflow with nodes and edges.
     *
     * @return array{workflow: Workflow|null, errors: array}
     */
    public function execute(
        string $prompt,
        string $userId,
        ?string $teamId = null,
    ): array {
        $availableAgents = Agent::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('status', 'active')
            ->select('id', 'name', 'role', 'goal')
            ->get()
            ->toArray();

        $systemPrompt = $this->buildSystemPrompt($availableAgents);

        try {
            $response = Prism::text()
                ->using('anthropic', 'claude-sonnet-4-20250514')
                ->withSystemPrompt($systemPrompt)
                ->withPrompt($prompt)
                ->withMaxTokens(4096)
                ->usingTemperature(0.3)
                ->withClientOptions(['timeout' => 60])
                ->asText();

            $parsed = $this->parseResponse($response->text);

            if (! $parsed) {
                return ['workflow' => null, 'errors' => ['Failed to parse LLM response into workflow structure.']];
            }

            // Create the workflow
            $workflow = $this->createWorkflow->execute(
                userId: $userId,
                name: $parsed['name'] ?? 'Generated Workflow',
                description: $parsed['description'] ?? "Generated from prompt: {$prompt}",
                nodes: $parsed['nodes'] ?? [],
                edges: $parsed['edges'] ?? [],
                maxLoopIterations: $parsed['max_loop_iterations'] ?? 5,
                teamId: $teamId,
            );

            // Validate the generated graph
            $errors = $this->graphValidator->validate($workflow);

            if (! empty($errors)) {
                Log::warning('GenerateWorkflowFromPromptAction: Generated workflow has validation errors', [
                    'workflow_id' => $workflow->id,
                    'errors' => $errors,
                ]);
            }

            return ['workflow' => $workflow, 'errors' => $errors];
        } catch (\Throwable $e) {
            Log::error('GenerateWorkflowFromPromptAction: LLM call failed', [
                'error' => $e->getMessage(),
            ]);

            return ['workflow' => null, 'errors' => [$e->getMessage()]];
        }
    }

    private function buildSystemPrompt(array $agents): string
    {
        $agentList = '';
        foreach ($agents as $agent) {
            $agentList .= "- ID: {$agent['id']}, Name: {$agent['name']}, Role: {$agent['role']}, Goal: {$agent['goal']}\n";
        }

        return <<<PROMPT
You are a workflow architect. Given a natural language description, generate a workflow graph as JSON.

## Available Agents
{$agentList}

## Node Types
- start: Entry point (exactly 1 required)
- end: Exit point (at least 1 required)
- agent: Executes an AI agent (requires agent_id from the list above)
- conditional: Binary routing based on conditions (needs >=2 outgoing edges with 1 default)
- switch: Multi-way routing based on expression value (needs >=2 outgoing edges, 1 default, each non-default edge has case_value)
- human_task: Requires human input via a form (needs form_schema in config)
- do_while: Loop with break condition (needs break_condition in config)

## Output Format
Return ONLY valid JSON (no markdown, no explanation) with this structure:
```
{
  "name": "Workflow Name",
  "description": "Brief description",
  "max_loop_iterations": 5,
  "nodes": [
    {"type": "start", "label": "Start", "position_x": 250, "position_y": 50},
    {"type": "agent", "label": "Research", "agent_id": "<uuid>", "position_x": 250, "position_y": 150, "config": {"prompt": "Research the topic"}},
    {"type": "human_task", "label": "Review", "position_x": 250, "position_y": 250, "config": {"prompt": "Review findings", "form_schema": {"fields": [{"name": "approved", "type": "select", "label": "Approve?", "required": true, "options": [{"value": "yes", "label": "Yes"}, {"value": "no", "label": "No"}]}]}}},
    {"type": "end", "label": "End", "position_x": 250, "position_y": 350}
  ],
  "edges": [
    {"source_node_index": 0, "target_node_index": 1},
    {"source_node_index": 1, "target_node_index": 2},
    {"source_node_index": 2, "target_node_index": 3}
  ]
}
```

## Rules
1. Always include exactly 1 start node and at least 1 end node.
2. Every node must be reachable from start and have a path to end.
3. Agent nodes MUST use an agent_id from the available agents list. Pick the best match by role/goal.
4. If no agent matches a task, still create the node with the closest agent and explain in the config.prompt.
5. Position nodes in a top-to-bottom layout with ~100px vertical spacing.
6. Conditional/switch nodes must have a default edge (is_default: true).
7. Edge references use 0-based node array indices (source_node_index, target_node_index).
8. Keep workflows focused and minimal — prefer fewer nodes over complex graphs.
PROMPT;
    }

    private function parseResponse(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if present
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```\s*$/', '', $text);
        }

        $decoded = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::warning('GenerateWorkflowFromPromptAction: Invalid JSON response', [
                'error' => json_last_error_msg(),
                'text_preview' => substr($text, 0, 200),
            ]);

            return null;
        }

        if (! isset($decoded['nodes']) || ! is_array($decoded['nodes'])) {
            return null;
        }

        return $decoded;
    }
}
