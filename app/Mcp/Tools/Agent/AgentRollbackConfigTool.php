<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Actions\RollbackAgentConfigAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class AgentRollbackConfigTool extends Tool
{
    protected string $name = 'agent_rollback_config';

    protected string $description = 'Roll back an agent configuration to the state captured before a specific revision. Creates a new rollback revision for traceability.';

    public function __construct(
        private readonly RollbackAgentConfigAction $rollback,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'revision_id' => $schema->string()
                ->description('The revision UUID to roll back to (restores the before_config of that revision)')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'revision_id' => 'required|string',
        ]);

        $agent = Agent::find($validated['agent_id']);

        if (! $agent) {
            return Response::error('Agent not found.');
        }

        $revision = AgentConfigRevision::withoutGlobalScopes()
            ->where('id', $validated['revision_id'])
            ->where('agent_id', $agent->id)
            ->first();

        if (! $revision) {
            return Response::error('Revision not found for this agent.');
        }

        $agent = $this->rollback->execute(
            agent: $agent,
            revision: $revision,
        );

        return Response::text(json_encode([
            'success' => true,
            'agent_id' => $agent->id,
            'rolled_back_to_revision' => $revision->id,
            'restored_config' => $revision->before_config,
        ]));
    }
}
