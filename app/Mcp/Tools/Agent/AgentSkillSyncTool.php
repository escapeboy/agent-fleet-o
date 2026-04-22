<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Skill\Models\Skill;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
#[AssistantTool('write')]
class AgentSkillSyncTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_skill_sync';

    protected string $description = 'Attach, detach, or replace skills on an agent. Mode "sync" replaces all skills, "attach" adds the given skills, "detach" removes them.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'skill_ids' => $schema->array()
                ->description('Array of skill UUIDs to attach/detach/sync')
                ->required(),
            'mode' => $schema->string()
                ->description('Operation mode: sync (replace all), attach (add), detach (remove). Default: sync')
                ->enum(['sync', 'attach', 'detach']),
        ];
    }

    public function handle(Request $request): Response
    {
        $agentId = $request->get('agent_id');
        $skillIds = $request->get('skill_ids', []);
        $mode = $request->get('mode', 'sync');

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($agentId);
        if (! $agent) {
            return $this->notFoundError('agent', $agentId);
        }

        if (! is_array($skillIds)) {
            return $this->invalidArgumentError('skill_ids must be an array of UUIDs.');
        }

        // Validate that all skill IDs exist
        $validSkills = Skill::whereIn('id', $skillIds)->pluck('id')->toArray();
        $invalidIds = array_diff($skillIds, $validSkills);
        if (! empty($invalidIds)) {
            return $this->invalidArgumentError('Invalid skill IDs: '.implode(', ', $invalidIds).'. Use skill_list to discover valid skill IDs.');
        }

        try {
            match ($mode) {
                'sync' => $agent->skills()->sync($skillIds),
                'attach' => $agent->skills()->syncWithoutDetaching($skillIds),
                'detach' => $agent->skills()->detach($skillIds),
            };

            $agent->load('skills:id,name');

            return Response::text(json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'mode' => $mode,
                'attached_skill_count' => $agent->skills->count(),
                'attached_skill_ids' => $agent->skills->pluck('id')->toArray(),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
