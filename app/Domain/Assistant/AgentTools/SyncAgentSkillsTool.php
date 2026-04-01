<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SyncAgentSkillsTool implements Tool
{
    public function name(): string
    {
        return 'sync_agent_skills';
    }

    public function description(): string
    {
        return 'Attach or sync skills to an agent. Mode "sync" replaces all skills, "attach" adds skills, "detach" removes them.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->required()->description('The agent UUID'),
            'skill_ids' => $schema->string()->required()->description('Comma-separated skill UUIDs (or JSON array)'),
            'mode' => $schema->string()->description('Operation: sync, attach, detach (default: sync)'),
        ];
    }

    public function handle(Request $request): string
    {
        $agent = Agent::find($request->get('agent_id'));
        if (! $agent) {
            return json_encode(['error' => 'Agent not found']);
        }

        $skillIds = $request->get('skill_ids');
        $ids = json_decode($skillIds, true) ?? array_filter(array_map('trim', explode(',', $skillIds)));
        $mode = in_array($request->get('mode'), ['sync', 'attach', 'detach']) ? $request->get('mode') : 'sync';

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
    }
}
