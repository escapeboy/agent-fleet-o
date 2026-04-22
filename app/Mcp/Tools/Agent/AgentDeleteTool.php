<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class AgentDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_delete';

    protected string $description = 'Soft-delete an AI agent. The agent must not have any active experiments. Set confirm=true to proceed.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID to delete')
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true to confirm deletion. This is a destructive action.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $agentId = $request->get('agent_id');
        $confirm = $request->get('confirm', false);

        if (! $confirm) {
            return $this->invalidArgumentError('confirm must be set to true to delete an agent. This action is irreversible.');
        }

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $agent = Agent::withoutGlobalScopes()->where('team_id', $teamId)->find($agentId);
        if (! $agent) {
            return $this->notFoundError('agent', $agentId);
        }

        // Guard: check for active experiments using this agent
        $activeExperimentCount = Experiment::whereHas('stages', function ($q) use ($agent) {
            $q->whereJsonContains('config->agent_id', $agent->id);
        })->whereNotIn('status', ['completed', 'killed', 'discarded', 'expired'])->count();

        if ($activeExperimentCount > 0) {
            return $this->failedPreconditionError("Cannot delete agent '{$agent->name}': it is used by {$activeExperimentCount} active experiment(s). Pause or complete those experiments first.");
        }

        try {
            $agentName = $agent->name;
            $agent->delete();

            return Response::text(json_encode([
                'success' => true,
                'agent_id' => $agentId,
                'name' => $agentName,
                'deleted_at' => now()->toIso8601String(),
                'message' => "Agent '{$agentName}' has been soft-deleted and can be restored by an administrator.",
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
