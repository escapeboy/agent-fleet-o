<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SyncAgentToolsTool implements Tool
{
    public function name(): string
    {
        return 'sync_agent_tools';
    }

    public function description(): string
    {
        return 'Attach or sync tools to an agent. Mode "sync" replaces all tools, "attach" adds tools, "detach" removes them.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()->required()->description('The agent UUID'),
            'tool_ids' => $schema->string()->required()->description('Comma-separated tool UUIDs (or JSON array)'),
            'mode' => $schema->string()->description('Operation: sync, attach, detach (default: sync)'),
        ];
    }

    public function handle(Request $request): string
    {
        $agent = Agent::find($request->get('agent_id'));
        if (! $agent) {
            return json_encode(['error' => 'Agent not found']);
        }

        $toolIds = $request->get('tool_ids');
        $ids = json_decode($toolIds, true) ?? array_filter(array_map('trim', explode(',', $toolIds)));
        $mode = in_array($request->get('mode'), ['sync', 'attach', 'detach']) ? $request->get('mode') : 'sync';

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
    }
}
