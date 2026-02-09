<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SkillResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class SkillController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $skills = QueryBuilder::for(Skill::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name', 'execution_count'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return SkillResource::collection($skills);
    }

    public function show(Skill $skill): SkillResource
    {
        return new SkillResource($skill);
    }

    public function store(Request $request, CreateSkillAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:llm,connector,rule,hybrid'],
            'description' => ['sometimes', 'string'],
            'execution_type' => ['sometimes', 'in:sync,async'],
            'risk_level' => ['sometimes', 'in:low,medium,high,critical'],
            'input_schema' => ['sometimes', 'array'],
            'output_schema' => ['sometimes', 'array'],
            'configuration' => ['sometimes', 'array'],
            'system_prompt' => ['sometimes', 'nullable', 'string'],
            'requires_approval' => ['sometimes', 'boolean'],
        ]);

        $skill = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            type: SkillType::from($request->type),
            description: $request->input('description', ''),
            systemPrompt: $request->system_prompt,
            requiresApproval: $request->boolean('requires_approval'),
            createdBy: $request->user()->id,
        );

        return (new SkillResource($skill))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Skill $skill, UpdateSkillAction $action): SkillResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string'],
            'status' => ['sometimes', 'in:draft,active,deprecated,archived'],
            'input_schema' => ['sometimes', 'array'],
            'output_schema' => ['sometimes', 'array'],
            'configuration' => ['sometimes', 'array'],
            'system_prompt' => ['sometimes', 'nullable', 'string'],
            'requires_approval' => ['sometimes', 'boolean'],
            'changelog' => ['sometimes', 'string'],
        ]);

        $skill = $action->execute(
            skill: $skill,
            attributes: $request->only([
                'name', 'description', 'status', 'input_schema',
                'output_schema', 'configuration', 'system_prompt', 'requires_approval',
            ]),
            changelog: $request->input('changelog'),
            updatedBy: $request->user()->id,
        );

        return new SkillResource($skill);
    }

    public function destroy(Skill $skill): JsonResponse
    {
        $skill->delete();

        return response()->json(['message' => 'Skill deleted.']);
    }

    public function versions(Skill $skill): JsonResponse
    {
        $versions = $skill->versions()->get()->map(fn ($v) => [
            'id' => $v->id,
            'version' => $v->version,
            'changelog' => $v->changelog,
            'created_by' => $v->created_by,
            'created_at' => $v->created_at->toISOString(),
        ]);

        return response()->json(['data' => $versions]);
    }
}
