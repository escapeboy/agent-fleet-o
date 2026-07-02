<?php

namespace App\Mcp\Tools\Agent;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\EquivocationDetector;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AgentEquivocationResetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'agent_equivocation_reset';

    protected string $description = 'Reset the equivocation counter for an agent after manual review. If the agent was auto-degraded, optionally restore it to active.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('The agent UUID')
                ->required(),
            'restore_active' => $schema->boolean()
                ->description('Also restore status to active if currently degraded (default true)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'agent_id' => 'required|string',
            'restore_active' => 'sometimes|boolean',
        ]);

        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        $agent = Agent::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['agent_id']);

        if (! $agent) {
            return $this->notFoundError('agent');
        }

        $previousCount = $agent->equivocation_count ?? 0;
        $previousStatus = $agent->status->value;

        app(EquivocationDetector::class)->reset($agent);

        $restoreActive = $validated['restore_active'] ?? true;
        if ($restoreActive && $agent->status === AgentStatus::Degraded) {
            $agent->update(['status' => AgentStatus::Active]);
        }

        return Response::text(json_encode([
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'previous_equivocation_count' => $previousCount,
            'previous_status' => $previousStatus,
            'status' => $agent->fresh()?->status->value,
        ]));
    }
}
