<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Actions\CreateAgentPolicyAction;
use App\Domain\Agent\Actions\RollbackAgentPolicyAction;
use App\Domain\Agent\Actions\UpdateAgentPolicyAction;
use App\Domain\Agent\Models\AgentPolicy;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AgentPolicyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Agent Policies
 */
class AgentPolicyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $policies = QueryBuilder::for(AgentPolicy::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('agent_id'),
                AllowedFilter::exact('enabled'),
                AllowedFilter::partial('name'),
            )
            ->allowedSorts('created_at', 'updated_at', 'name')
            ->defaultSort('-updated_at')
            ->with('currentVersion')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return AgentPolicyResource::collection($policies);
    }

    public function show(AgentPolicy $agentPolicy): AgentPolicyResource
    {
        return new AgentPolicyResource($agentPolicy->load('currentVersion'));
    }

    public function store(Request $request, CreateAgentPolicyAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'agent_id' => ['sometimes', 'nullable', 'string',
                Rule::exists('agents', 'id')->where('team_id', $request->user()?->current_team_id)],
            'rules' => ['sometimes', 'array'],
            'enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $policy = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            agentId: $request->input('agent_id'),
            rules: $request->input('rules', []),
            enabled: (bool) $request->input('enabled', false),
            createdBy: $request->user()->id,
            notes: $request->input('notes'),
        );

        return (new AgentPolicyResource($policy->load('currentVersion')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, AgentPolicy $agentPolicy, UpdateAgentPolicyAction $action): AgentPolicyResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'rules' => ['sometimes', 'array'],
            'enabled' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $policy = $action->execute(
            policy: $agentPolicy,
            rules: $request->has('rules') ? $request->input('rules') : null,
            name: $request->input('name'),
            enabled: $request->has('enabled') ? (bool) $request->input('enabled') : null,
            createdBy: $request->user()->id,
            notes: $request->input('notes'),
        );

        return new AgentPolicyResource($policy->load('currentVersion'));
    }

    public function rollback(Request $request, AgentPolicy $agentPolicy, RollbackAgentPolicyAction $action): JsonResponse
    {
        $validated = $request->validate([
            'version_id' => ['required', 'string'],
        ]);

        try {
            $policy = $action->execute($agentPolicy, $validated['version_id'], $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new AgentPolicyResource($policy->load('currentVersion')))->response();
    }
}
