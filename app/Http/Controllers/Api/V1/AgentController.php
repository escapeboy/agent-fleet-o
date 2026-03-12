<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Actions\RecordAgentConfigRevisionAction;
use App\Domain\Agent\Actions\RollbackAgentConfigAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentConfigRevision;
use App\Domain\Agent\Models\AgentRuntimeState;
use App\Domain\Agent\Services\AgentRuntimeStateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAgentRequest;
use App\Http\Requests\Api\V1\UpdateAgentRequest;
use App\Http\Resources\Api\V1\AgentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Agents
 */
class AgentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $agents = QueryBuilder::for(Agent::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('name'),
                AllowedFilter::exact('provider'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name', 'status'])
            ->defaultSort('-created_at')
            ->with('skills')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return AgentResource::collection($agents);
    }

    public function show(Agent $agent): AgentResource
    {
        return new AgentResource($agent->load(['skills', 'runtimeState']));
    }

    public function store(StoreAgentRequest $request, CreateAgentAction $action): JsonResponse
    {
        $agent = $action->execute(
            name: $request->name,
            provider: $request->provider,
            model: $request->model,
            capabilities: $request->input('capabilities', []),
            config: $request->input('config', []),
            teamId: $request->user()->current_team_id,
            role: $request->role,
            goal: $request->goal,
            backstory: $request->backstory,
            constraints: $request->input('constraints', []),
            budgetCapCredits: $request->budget_cap_credits,
            skillIds: $request->input('skill_ids', []),
        );

        return (new AgentResource($agent->load(['skills', 'runtimeState'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        UpdateAgentRequest $request,
        Agent $agent,
        RecordAgentConfigRevisionAction $recordRevision,
    ): AgentResource {
        $data = $request->validated();
        $skillIds = $data['skill_ids'] ?? null;
        unset($data['skill_ids']);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $recordRevision->execute(
            agent: $agent,
            newData: $data,
            source: 'api',
            userId: $request->user()?->id,
        );

        $agent->update($data);

        if ($skillIds !== null) {
            $syncData = [];
            foreach ($skillIds as $index => $skillId) {
                $syncData[$skillId] = ['priority' => $index];
            }
            $agent->skills()->sync($syncData);
        }

        return new AgentResource($agent->fresh()->load('skills'));
    }

    /**
     * @response 200 {"message": "Agent deleted."}
     */
    public function destroy(Agent $agent): JsonResponse
    {
        $agent->delete();

        return response()->json(['message' => 'Agent deleted.']);
    }

    public function toggleStatus(Request $request, Agent $agent): AgentResource
    {
        $newStatus = $agent->status === AgentStatus::Active
            ? AgentStatus::Disabled
            : AgentStatus::Active;

        $agent->update(['status' => $newStatus]);

        return new AgentResource($agent->fresh());
    }

    public function configHistory(Request $request, Agent $agent): JsonResponse
    {
        $limit = min((int) $request->input('limit', 20), 50);

        $revisions = AgentConfigRevision::where('agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AgentConfigRevision $r) => [
                'id' => $r->id,
                'source' => $r->source,
                'changed_keys' => $r->changed_keys,
                'before_config' => $r->before_config,
                'after_config' => $r->after_config,
                'rolled_back_from_revision_id' => $r->rolled_back_from_revision_id,
                'notes' => $r->notes,
                'created_at' => $r->created_at?->toISOString(),
            ]);

        return response()->json([
            'agent_id' => $agent->id,
            'total' => AgentConfigRevision::where('agent_id', $agent->id)->count(),
            'revisions' => $revisions,
        ]);
    }

    public function rollback(
        Request $request,
        Agent $agent,
        RollbackAgentConfigAction $action,
    ): JsonResponse {
        $request->validate(['revision_id' => 'required|string']);

        $revision = AgentConfigRevision::where('id', $request->revision_id)
            ->where('agent_id', $agent->id)
            ->firstOrFail();

        $agent = $action->execute(
            agent: $agent,
            revision: $revision,
            userId: $request->user()?->id,
        );

        return response()->json([
            'success' => true,
            'agent_id' => $agent->id,
            'rolled_back_to_revision' => $revision->id,
            'restored_config' => $revision->before_config,
        ]);
    }

    public function runtimeState(Agent $agent): JsonResponse
    {
        $state = AgentRuntimeState::where('agent_id', $agent->id)->first();

        if (! $state) {
            return response()->json(['agent_id' => $agent->id, 'state' => null], 404);
        }

        return response()->json([
            'agent_id' => $agent->id,
            'session_id' => $state->session_id,
            'total_executions' => $state->total_executions,
            'total_input_tokens' => $state->total_input_tokens,
            'total_output_tokens' => $state->total_output_tokens,
            'total_cost_credits' => $state->total_cost_credits,
            'last_error' => $state->last_error,
            'last_active_at' => $state->last_active_at?->toISOString(),
        ]);
    }

    public function resetRuntimeSession(Agent $agent, AgentRuntimeStateService $service): JsonResponse
    {
        $service->resetSession($agent);

        return response()->json(['success' => true, 'agent_id' => $agent->id]);
    }
}
