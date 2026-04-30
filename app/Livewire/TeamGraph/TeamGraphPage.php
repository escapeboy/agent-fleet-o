<?php

namespace App\Livewire\TeamGraph;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use App\Domain\Experiment\Models\ExperimentStateTransition;
use App\Models\User;
use Livewire\Component;

class TeamGraphPage extends Component
{
    /** @var array<string, mixed> */
    public array $graph = ['nodes' => [], 'edges' => []];

    /** @var array<int, array<string, mixed>> */
    public array $feed = [];

    public ?string $selectedNodeId = null;

    /** @var array<int, array<string, mixed>> */
    public array $drawerActivity = [];

    public ?string $drawerLabel = null;

    public function mount(): void
    {
        $this->buildGraph();
        $this->refreshFeed();
    }

    public function refreshFeed(): void
    {
        $teamId = auth()->user()->current_team_id;
        if (! $teamId) {
            $this->feed = [];

            return;
        }

        $execs = AgentExecution::query()
            ->where('team_id', $teamId)
            ->with('agent:id,name')
            ->latest('updated_at')
            ->limit(20)
            ->get();

        $transitions = ExperimentStateTransition::query()
            ->whereHas('experiment', fn ($q) => $q->where('team_id', $teamId))
            ->with('experiment:id,title,team_id')
            ->latest('created_at')
            ->limit(20)
            ->get();

        $items = [];

        foreach ($execs as $exec) {
            $input = is_array($exec->input ?? null) ? $exec->input : [];
            $task = $input['task'] ?? $input['content'] ?? $input['query'] ?? null;
            $status = (string) ($exec->status ?? 'unknown');
            $items[] = [
                'id' => 'exec:'.$exec->id,
                'kind' => 'agent.executed',
                'actor_id' => $exec->agent_id,
                'actor_kind' => 'agent',
                'actor_label' => $exec->agent?->name ?? 'Agent',
                'summary' => is_string($task) && trim($task) !== ''
                    ? "{$status}: ".\Str::limit($task, 80)
                    : $status,
                'at' => optional($exec->updated_at)->toIso8601String(),
            ];
        }

        foreach ($transitions as $t) {
            $items[] = [
                'id' => 'transition:'.$t->id,
                'kind' => 'experiment.transitioned',
                'actor_id' => $t->experiment_id,
                'actor_kind' => 'experiment',
                'actor_label' => $t->experiment?->title ?: 'Experiment',
                'summary' => "{$t->from_state} → {$t->to_state}",
                'at' => optional($t->created_at)->toIso8601String(),
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        $this->feed = array_slice($items, 0, 20);
    }

    public function openDrawer(string $nodeId): void
    {
        $this->selectedNodeId = $nodeId;
        $teamId = auth()->user()->current_team_id;

        [$kind, $id] = array_pad(explode(':', $nodeId, 2), 2, null);

        if ($kind === 'agent' && $id) {
            $agent = Agent::query()->where('team_id', $teamId)->where('id', $id)->first();
            $this->drawerLabel = $agent?->name ?? 'Agent';
            $this->drawerActivity = AgentExecution::query()
                ->where('team_id', $teamId)
                ->where('agent_id', $id)
                ->latest('updated_at')
                ->limit(5)
                ->get(['id', 'status', 'updated_at', 'duration_ms', 'cost_credits'])
                ->map(fn ($e) => [
                    'id' => $e->id,
                    'status' => (string) $e->status,
                    'at' => optional($e->updated_at)->diffForHumans(),
                    'duration_ms' => $e->duration_ms,
                    'cost_credits' => $e->cost_credits,
                ])
                ->all();

            return;
        }

        if ($kind === 'crew' && $id) {
            $crew = Crew::query()->where('team_id', $teamId)->where('id', $id)->first();
            $this->drawerLabel = $crew?->name ?? 'Crew';
            $this->drawerActivity = $crew
                ? $crew->executions()->latest('created_at')->limit(5)
                    ->get(['id', 'status', 'created_at'])
                    ->map(fn ($e) => [
                        'id' => $e->id,
                        'status' => (string) $e->status,
                        'at' => optional($e->created_at)->diffForHumans(),
                    ])->all()
                : [];

            return;
        }

        if ($kind === 'human' && $id) {
            $user = User::query()->where('id', $id)->first();
            $this->drawerLabel = $user?->name ?? 'User';
            $this->drawerActivity = []; // Human activity feed deferred — no audit-by-user query in v1.

            return;
        }

        $this->drawerLabel = null;
        $this->drawerActivity = [];
    }

    public function closeDrawer(): void
    {
        $this->selectedNodeId = null;
        $this->drawerLabel = null;
        $this->drawerActivity = [];
    }

    private function buildGraph(): void
    {
        $teamId = auth()->user()->current_team_id;
        if (! $teamId) {
            return;
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

        $team = auth()->user()->currentTeam;
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

        $this->graph = ['nodes' => $nodes, 'edges' => $edges];
    }

    public function render()
    {
        return view('livewire.team-graph.team-graph-page')
            ->layout('layouts.app', ['header' => 'Team Graph']);
    }
}
