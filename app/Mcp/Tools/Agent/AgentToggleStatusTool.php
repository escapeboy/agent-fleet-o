<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\DisableAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentToggleStatusTool extends Tool
{
    protected string $name = 'agent_toggle_status';

    protected string $description = 'Enable or disable an AI agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'status' => $schema->string()
                ->description('Target status: active or disabled')
                ->enum(['active', 'disabled'])
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'status' => 'required|string|in:active,disabled',
        ]);

        $agent = Agent::find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        try {
            if ($validated['status'] === 'disabled') {
                app(DisableAgentAction::class)->execute($agent);
            } else {
                $agent->update(['status' => AgentStatus::Active]);
            }

            return Response::text(json_encode([
                'success' => true,
                'agent_id' => $agent->id,
                'status' => $validated['status'],
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
