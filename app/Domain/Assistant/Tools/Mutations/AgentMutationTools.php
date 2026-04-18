<?php

namespace App\Domain\Assistant\Tools\Mutations;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Models\Agent;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

final class AgentMutationTools
{
    /**
     * @return array<PrismToolObject>
     */
    public static function writeTools(): array
    {
        return [
            self::createAgent(),
            self::updateAgent(),
            self::syncAgentSkills(),
            self::syncAgentTools(),
        ];
    }

    /**
     * @return array<PrismToolObject>
     */
    public static function destructiveTools(): array
    {
        return [
            self::deleteAgent(),
        ];
    }

    public static function createAgent(): PrismToolObject
    {
        return PrismTool::as('create_agent')
            ->for('Create a new AI agent')
            ->withStringParameter('name', 'Agent name', required: true)
            ->withStringParameter('role', 'Agent role description')
            ->withStringParameter('goal', 'Agent goal')
            ->withStringParameter('backstory', 'Agent backstory')
            ->withStringParameter('provider', 'LLM provider (anthropic, openai, google). Default: anthropic')
            ->withStringParameter('model', 'LLM model name. Default: claude-sonnet-4-5')
            ->using(function (string $name, ?string $role = null, ?string $goal = null, ?string $backstory = null, ?string $provider = null, ?string $model = null) {
                try {
                    $agent = app(CreateAgentAction::class)->execute(
                        name: $name,
                        provider: $provider ?? 'anthropic',
                        model: $model ?? 'claude-sonnet-4-5',
                        role: $role,
                        goal: $goal,
                        backstory: $backstory,
                        teamId: auth()->user()->current_team_id,
                    );

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'name' => $agent->name,
                        'status' => $agent->status->value,
                        'url' => route('agents.show', $agent),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function updateAgent(): PrismToolObject
    {
        return PrismTool::as('update_agent')
            ->for('Update an existing AI agent. Only provided fields will be changed. Use this to change the LLM provider, model, role, goal, backstory, or budget.')
            ->withStringParameter('agent_id', 'The agent UUID', required: true)
            ->withStringParameter('name', 'New agent name')
            ->withStringParameter('role', 'New role description')
            ->withStringParameter('goal', 'New goal')
            ->withStringParameter('backstory', 'New backstory')
            ->withStringParameter('provider', 'LLM provider to switch to (anthropic, openai, google, claude-code, codex)')
            ->withStringParameter('model', 'LLM model name (e.g. claude-sonnet-4-5, claude-opus-4-6, gpt-4o, gemini-2.5-pro)')
            ->withNumberParameter('budget_cap_credits', 'Per-agent budget cap in credits. Set to 0 to remove cap.')
            ->using(function (string $agent_id, ?string $name = null, ?string $role = null, ?string $goal = null, ?string $backstory = null, ?string $provider = null, ?string $model = null, ?float $budget_cap_credits = null) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found.']);
                }

                $data = array_filter([
                    'name' => $name,
                    'role' => $role,
                    'goal' => $goal,
                    'backstory' => $backstory,
                    'provider' => $provider,
                    'model' => $model,
                ], fn ($v) => $v !== null);

                if ($budget_cap_credits !== null) {
                    $data['budget_cap_credits'] = (int) $budget_cap_credits === 0 ? null : (int) $budget_cap_credits;
                }

                if (empty($data)) {
                    return json_encode(['error' => 'No fields to update. Provide at least one of: name, role, goal, backstory, provider, model, budget_cap_credits.']);
                }

                try {
                    $agent->update($data);

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'updated_fields' => array_keys($data),
                        'provider' => $agent->fresh()->provider,
                        'model' => $agent->fresh()->model,
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function syncAgentSkills(): PrismToolObject
    {
        return PrismTool::as('sync_agent_skills')
            ->for('Attach or sync skills to an agent. Mode "sync" replaces all skills, "attach" adds skills, "detach" removes them.')
            ->withStringParameter('agent_id', 'The agent UUID', required: true)
            ->withStringParameter('skill_ids', 'Comma-separated skill UUIDs (or JSON array)', required: true)
            ->withStringParameter('mode', 'Operation: sync, attach, detach (default: sync)')
            ->using(function (string $agent_id, string $skill_ids, ?string $mode = null) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                $ids = json_decode($skill_ids, true) ?? array_filter(array_map('trim', explode(',', $skill_ids)));
                $mode = in_array($mode, ['sync', 'attach', 'detach']) ? $mode : 'sync';

                try {
                    match ($mode) {
                        'sync' => $agent->skills()->sync($ids),
                        'attach' => $agent->skills()->syncWithoutDetaching($ids),
                        'detach' => $agent->skills()->detach($ids),
                    };

                    $agent->load('skills:id,name');

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'mode' => $mode,
                        'attached_skill_count' => $agent->skills->count(),
                        'attached_skills' => $agent->skills->pluck('name')->toArray(),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function syncAgentTools(): PrismToolObject
    {
        return PrismTool::as('sync_agent_tools')
            ->for('Attach or sync tools to an agent. Mode "sync" replaces all tools, "attach" adds tools, "detach" removes them.')
            ->withStringParameter('agent_id', 'The agent UUID', required: true)
            ->withStringParameter('tool_ids', 'Comma-separated tool UUIDs (or JSON array)', required: true)
            ->withStringParameter('mode', 'Operation: sync, attach, detach (default: sync)')
            ->using(function (string $agent_id, string $tool_ids, ?string $mode = null) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                $ids = json_decode($tool_ids, true) ?? array_filter(array_map('trim', explode(',', $tool_ids)));
                $mode = in_array($mode, ['sync', 'attach', 'detach']) ? $mode : 'sync';

                try {
                    match ($mode) {
                        'sync' => $agent->tools()->sync($ids),
                        'attach' => $agent->tools()->syncWithoutDetaching($ids),
                        'detach' => $agent->tools()->detach($ids),
                    };

                    $agent->load('tools:id,name');

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent->id,
                        'mode' => $mode,
                        'attached_tool_count' => $agent->tools->count(),
                        'attached_tools' => $agent->tools->pluck('name')->toArray(),
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }

    public static function deleteAgent(): PrismToolObject
    {
        return PrismTool::as('delete_agent')
            ->for('Soft-delete an AI agent. The agent must not have active experiments. This is a destructive action.')
            ->withStringParameter('agent_id', 'The agent UUID to delete', required: true)
            ->using(function (string $agent_id) {
                $agent = Agent::find($agent_id);
                if (! $agent) {
                    return json_encode(['error' => 'Agent not found']);
                }

                try {
                    $agentName = $agent->name;
                    $agent->delete();

                    return json_encode([
                        'success' => true,
                        'agent_id' => $agent_id,
                        'name' => $agentName,
                        'message' => "Agent '{$agentName}' has been deleted.",
                    ]);
                } catch (\Throwable $e) {
                    return json_encode(['error' => $e->getMessage()]);
                }
            });
    }
}
