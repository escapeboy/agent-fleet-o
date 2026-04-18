<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Enums\AgentHookType;
use App\Domain\Agent\Models\AgentHook;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentHookCreateTool extends Tool
{
    protected string $name = 'agent_hook_create';

    protected string $description = 'Create a lifecycle hook on an agent. Hooks run at specific execution points (pre_execute, post_execute, pre_reasoning, post_reasoning, on_tool_call, on_error). Set agent_id to null for team-wide hooks.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()
                ->description('Hook display name')
                ->required(),
            'agent_id' => $schema->string()
                ->description('Agent UUID. Null = team-wide hook applying to all agents'),
            'position' => $schema->string()
                ->description('Lifecycle position: pre_execute, post_execute, pre_reasoning, post_reasoning, on_tool_call, on_error')
                ->enum(array_map(fn ($c) => $c->value, AgentHookPosition::cases()))
                ->required(),
            'type' => $schema->string()
                ->description('Hook type: prompt_injection, output_transform, guardrail, notification, context_enrichment')
                ->enum(array_map(fn ($c) => $c->value, AgentHookType::cases()))
                ->required(),
            'config' => $schema->object()
                ->description('Type-specific config. prompt_injection: {text, target}. output_transform: {transform, prefix/suffix/search/replace}. guardrail: {rules: [{field, operator, value, message}]}. notification: {channel, message}. context_enrichment: {source, target, content}.')
                ->required(),
            'priority' => $schema->number()
                ->description('Execution priority (lower = runs first, default 100)')
                ->default(100),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = auth()->user()->current_team_id;

        $hook = AgentHook::create([
            'team_id' => $teamId,
            'agent_id' => $request->string('agent_id') ?: null,
            'name' => $request->string('name'),
            'position' => $request->string('position'),
            'type' => $request->string('type'),
            'config' => $request->object('config') ?? [],
            'priority' => (int) ($request->number('priority') ?? 100),
            'enabled' => true,
        ]);

        return Response::text("Hook '{$hook->name}' created (ID: {$hook->id})");
    }
}
