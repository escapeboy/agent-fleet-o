<?php

namespace App\Mcp\Tools\Shared;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Shared\Models\Team;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class TeamGraphGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'team_graph_get';

    protected string $description = 'Return the current team\'s graph: agents, human members, crews, and crew-membership edges. Powers the /team-graph live visualization.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->notFoundError('team');
        }

        $nodes = [];
        $edges = [];

        $agents = Agent::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->select('id', 'name', 'role', 'provider', 'model', 'status')
            ->limit(200)
            ->get();

        foreach ($agents as $agent) {
            $nodes[] = [
                'id' => 'agent:'.$agent->id,
                'type' => 'agent',
                'label' => $agent->name,
                'vendor' => $agent->provider,
                'model' => $agent->model,
                'status' => $agent->status instanceof AgentStatus ? $agent->status->value : (string) $agent->status,
                'role' => $agent->role,
            ];
        }

        $team = Team::find($teamId);
        if ($team) {
            foreach ($team->users()->select('users.id', 'users.name')->limit(50)->get() as $u) {
                $initials = collect(explode(' ', (string) $u->name))
                    ->map(fn ($p) => mb_substr($p, 0, 1))
                    ->take(2)
                    ->implode('');
                $nodes[] = [
                    'id' => 'human:'.$u->id,
                    'type' => 'human',
                    'label' => $u->name ?: 'User',
                    'initials' => mb_strtoupper($initials ?: '?'),
                ];
            }
        }

        $crews = Crew::query()
            ->withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->select('id', 'name', 'process_type')
            ->limit(50)
            ->get();

        foreach ($crews as $crew) {
            $nodes[] = [
                'id' => 'crew:'.$crew->id,
                'type' => 'crew',
                'label' => $crew->name,
                'process_type' => $crew->process_type instanceof \BackedEnum ? $crew->process_type->value : (string) $crew->process_type,
            ];
        }

        $crewMembers = CrewMember::query()
            ->whereIn('crew_id', $crews->pluck('id'))
            ->whereIn('agent_id', $agents->pluck('id'))
            ->select('id', 'crew_id', 'agent_id', 'role')
            ->get();

        foreach ($crewMembers as $cm) {
            $edges[] = [
                'id' => 'cm:'.$cm->id,
                'source' => 'agent:'.$cm->agent_id,
                'target' => 'crew:'.$cm->crew_id,
                'kind' => 'member',
                'role' => $cm->role instanceof \BackedEnum ? $cm->role->value : (string) $cm->role,
            ];
        }

        return Response::text(json_encode([
            'team_id' => $teamId,
            'nodes' => $nodes,
            'edges' => $edges,
            'counts' => [
                'agents' => $agents->count(),
                'humans' => isset($team) ? ($team->users()->count() ?? 0) : 0,
                'crews' => $crews->count(),
            ],
        ]));
    }
}
