<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class AgentStrictModeSetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_strict_mode_set';

    protected string $description = 'Enable or disable strict protocol mode for an agent. When enabled, every response is audited and tool usage is validated against the allowed list.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'strict_mode' => $schema->boolean()
                ->description('Whether to enable strict protocol mode')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'strict_mode' => 'required|boolean',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFound('Agent', $validated['agent_id']);
        }

        $agent->update(['strict_mode' => $validated['strict_mode']]);

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'strict_mode' => $agent->strict_mode,
            'message' => $validated['strict_mode']
                ? 'Strict protocol mode enabled. All responses will be audited.'
                : 'Strict protocol mode disabled.',
        ], JSON_PRETTY_PRINT));
    }
}
