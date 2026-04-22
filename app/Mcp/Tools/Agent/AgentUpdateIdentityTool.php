<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentUpdateIdentityTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_update_identity';

    protected string $description = 'Update an agent\'s structured identity template (SOUL.md-like). Sets personality, rules, context injection, and output format sections that compile into the system prompt.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'personality' => $schema->string()
                ->description('Personality description for the agent'),
            'rules' => $schema->array()
                ->description('Array of rule strings the agent must follow')
                ->items($schema->string()),
            'context_injection' => $schema->string()
                ->description('Context template with {{variable}} placeholders (e.g. {{agent.name}}, {{current_date}}, {{recent_memories}}, {{available_tools}})'),
            'output_format' => $schema->string()
                ->description('Instructions for how the agent should format its output'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'personality' => 'nullable|string',
            'rules' => 'nullable|array',
            'rules.*' => 'string',
            'context_injection' => 'nullable|string',
            'output_format' => 'nullable|string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }

        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['agent_id']);
        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $template = $agent->system_prompt_template ?? [];

        if (array_key_exists('personality', $validated)) {
            $template['personality'] = $validated['personality'];
        }
        if (array_key_exists('rules', $validated)) {
            $template['rules'] = $validated['rules'];
        }
        if (array_key_exists('context_injection', $validated)) {
            $template['context_injection'] = $validated['context_injection'];
        }
        if (array_key_exists('output_format', $validated)) {
            $template['output_format'] = $validated['output_format'];
        }

        $agent->update(['system_prompt_template' => $template]);

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'system_prompt_template' => $template,
        ]));
    }
}
