<?php

namespace App\Mcp\Tools\Workflow;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WorkflowNodeAddTool extends Tool
{
    protected string $name = 'workflow_node_add';

    protected string $description = <<<'DESC'
Add a new node to an existing workflow. The node is appended after existing nodes. Use workflow_edge_add to connect it to other nodes.

Node types:
  agent              — executes an agent (set agent_id)
  conditional        — branches on expression
  human_task         — waits for human completion
  switch             — multi-way branch on expression
  dynamic_fork       — parallel split
  do_while           — loop until condition met
  llm                — direct LLM call; config: {model, prompt_template}
  http_request       — outbound HTTP with SSRF guard; config: {url, method, headers, body_template}
  parameter_extractor — extract structured JSON from input; config: {schema} (JSON Schema)
  variable_aggregator — merge outputs from multiple predecessor nodes; no special config
  template_transform — Mustache-style {{variable}} rendering, zero LLM cost; config: {template}
  knowledge_retrieval — pgvector semantic search against a KnowledgeBase; config: {knowledge_base_id, top_k}
DESC;

    public function schema(JsonSchema $schema): array
    {
        return [
            'workflow_id' => $schema->string()
                ->description('The workflow UUID to add the node to')
                ->required(),
            'type' => $schema->string()
                ->description('Node type (see tool description for full list and config details)')
                ->enum(['agent', 'conditional', 'human_task', 'switch', 'dynamic_fork', 'do_while', 'llm', 'http_request', 'parameter_extractor', 'variable_aggregator', 'template_transform', 'knowledge_retrieval'])
                ->required(),
            'label' => $schema->string()
                ->description('Human-readable label for this node')
                ->required(),
            'agent_id' => $schema->string()
                ->description('UUID of the agent to assign (for agent nodes)'),
            'skill_id' => $schema->string()
                ->description('UUID of the skill to assign'),
            'crew_id' => $schema->string()
                ->description('UUID of the crew to assign (for crew nodes)'),
            'config' => $schema->object()
                ->description('Node configuration (e.g. {"timeout": 300, "retries": 2})'),
            'expression' => $schema->string()
                ->description('Condition expression for conditional/switch nodes'),
            'position_x' => $schema->integer()
                ->description('Horizontal canvas position')
                ->default(0),
            'position_y' => $schema->integer()
                ->description('Vertical canvas position')
                ->default(0),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'workflow_id' => 'required|string',
            'type' => 'required|string|in:agent,conditional,human_task,switch,dynamic_fork,do_while,llm,http_request,parameter_extractor,variable_aggregator,template_transform,knowledge_retrieval',
            'label' => 'required|string|max:255',
            'agent_id' => 'nullable|uuid|exists:agents,id',
            'skill_id' => 'nullable|uuid|exists:skills,id',
            'crew_id' => 'nullable|uuid|exists:crews,id',
            'config' => 'nullable|array',
            'expression' => 'nullable|string|max:500',
            'position_x' => 'nullable|integer',
            'position_y' => 'nullable|integer',
        ]);

        $teamId = auth()->user()?->current_team_id;

        $workflow = Workflow::where('team_id', $teamId)->find($validated['workflow_id']);

        if (! $workflow) {
            return Response::error('Workflow not found.');
        }

        $maxOrder = $workflow->nodes()->max('order') ?? -1;

        $node = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => $validated['type'],
            'label' => $validated['label'],
            'agent_id' => $validated['agent_id'] ?? null,
            'skill_id' => $validated['skill_id'] ?? null,
            'crew_id' => $validated['crew_id'] ?? null,
            'config' => $validated['config'] ?? [],
            'expression' => $validated['expression'] ?? null,
            'position_x' => $validated['position_x'] ?? 0,
            'position_y' => $validated['position_y'] ?? 0,
            'order' => $maxOrder + 1,
        ]);

        return Response::text(json_encode([
            'success' => true,
            'node' => [
                'id' => $node->id,
                'type' => $node->type->value,
                'label' => $node->label,
                'agent_id' => $node->agent_id,
                'skill_id' => $node->skill_id,
                'crew_id' => $node->crew_id,
                'config' => $node->config,
                'expression' => $node->expression,
                'position_x' => $node->position_x,
                'position_y' => $node->position_y,
                'order' => $node->order,
            ],
            'workflow_id' => $workflow->id,
            'total_nodes' => $workflow->nodes()->count(),
        ]));
    }
}
