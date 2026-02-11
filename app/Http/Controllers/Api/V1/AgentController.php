<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Actions\CreateAgentAction;
use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
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
            ->cursorPaginate($request->input('per_page', 15));

        return AgentResource::collection($agents);
    }

    public function show(Agent $agent): AgentResource
    {
        return new AgentResource($agent->load('skills'));
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

        return (new AgentResource($agent->load('skills')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAgentRequest $request, Agent $agent): AgentResource
    {
        $data = $request->validated();
        $skillIds = $data['skill_ids'] ?? null;
        unset($data['skill_ids']);

        if (isset($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

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
}
