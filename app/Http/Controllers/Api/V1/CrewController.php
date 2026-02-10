<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Crew\Actions\CreateCrewAction;
use App\Domain\Crew\Actions\ExecuteCrewAction;
use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewProcessType;
use App\Domain\Crew\Enums\CrewStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CrewExecutionResource;
use App\Http\Resources\Api\V1\CrewResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class CrewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $crews = QueryBuilder::for(Crew::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('process_type'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name', 'status'])
            ->defaultSort('-created_at')
            ->with(['coordinator', 'qaAgent'])
            ->withCount('executions')
            ->cursorPaginate($request->input('per_page', 15));

        return CrewResource::collection($crews);
    }

    public function show(Crew $crew): CrewResource
    {
        return new CrewResource(
            $crew->load(['coordinator', 'qaAgent', 'members.agent'])
                ->loadCount('executions')
        );
    }

    public function store(Request $request, CreateCrewAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'coordinator_agent_id' => ['required', 'uuid', 'exists:agents,id'],
            'qa_agent_id' => ['required', 'uuid', 'exists:agents,id'],
            'process_type' => ['sometimes', new Enum(CrewProcessType::class)],
            'max_task_iterations' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'quality_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'worker_agent_ids' => ['sometimes', 'array'],
            'worker_agent_ids.*' => ['uuid', 'exists:agents,id'],
            'settings' => ['sometimes', 'array'],
        ]);

        $crew = $action->execute(
            userId: $request->user()->id,
            name: $request->name,
            coordinatorAgentId: $request->coordinator_agent_id,
            qaAgentId: $request->qa_agent_id,
            description: $request->description,
            processType: $request->process_type
                ? CrewProcessType::from($request->process_type)
                : CrewProcessType::Hierarchical,
            maxTaskIterations: $request->input('max_task_iterations', 3),
            qualityThreshold: (float) $request->input('quality_threshold', 0.70),
            workerAgentIds: $request->input('worker_agent_ids', []),
            settings: $request->input('settings', []),
            teamId: $request->user()->current_team_id,
        );

        return (new CrewResource($crew->load(['coordinator', 'qaAgent', 'members.agent'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Crew $crew, UpdateCrewAction $action): CrewResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'coordinator_agent_id' => ['sometimes', 'uuid', 'exists:agents,id'],
            'qa_agent_id' => ['sometimes', 'uuid', 'exists:agents,id'],
            'process_type' => ['sometimes', new Enum(CrewProcessType::class)],
            'max_task_iterations' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'quality_threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'worker_agent_ids' => ['sometimes', 'array'],
            'worker_agent_ids.*' => ['uuid', 'exists:agents,id'],
            'status' => ['sometimes', new Enum(CrewStatus::class)],
            'settings' => ['sometimes', 'array'],
        ]);

        $crew = $action->execute(
            crew: $crew,
            name: $request->input('name'),
            description: $request->input('description'),
            coordinatorAgentId: $request->input('coordinator_agent_id'),
            qaAgentId: $request->input('qa_agent_id'),
            processType: $request->process_type
                ? CrewProcessType::from($request->process_type)
                : null,
            maxTaskIterations: $request->input('max_task_iterations'),
            qualityThreshold: $request->has('quality_threshold')
                ? (float) $request->quality_threshold
                : null,
            workerAgentIds: $request->input('worker_agent_ids'),
            status: $request->status ? CrewStatus::from($request->status) : null,
            settings: $request->input('settings'),
        );

        return new CrewResource($crew->load(['coordinator', 'qaAgent', 'members.agent']));
    }

    public function destroy(Crew $crew): JsonResponse
    {
        $crew->delete();

        return response()->json(['message' => 'Crew deleted.']);
    }

    public function execute(Request $request, Crew $crew, ExecuteCrewAction $action): JsonResponse
    {
        $request->validate([
            'goal' => ['required', 'string', 'max:2000'],
            'experiment_id' => ['sometimes', 'nullable', 'uuid', 'exists:experiments,id'],
        ]);

        $execution = $action->execute(
            crew: $crew,
            goal: $request->goal,
            teamId: $request->user()->current_team_id,
            experimentId: $request->input('experiment_id'),
        );

        return (new CrewExecutionResource($execution))
            ->response()
            ->setStatusCode(201);
    }

    public function executions(Request $request, Crew $crew): AnonymousResourceCollection
    {
        $executions = QueryBuilder::for(
            CrewExecution::query()->where('crew_id', $crew->id)
        )
            ->allowedFilters([
                AllowedFilter::exact('status'),
            ])
            ->allowedSorts(['created_at', 'status', 'total_cost_credits', 'duration_ms'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return CrewExecutionResource::collection($executions);
    }

    public function showExecution(Crew $crew, CrewExecution $execution): CrewExecutionResource
    {
        if ($execution->crew_id !== $crew->id) {
            abort(404);
        }

        return new CrewExecutionResource($execution->load('taskExecutions'));
    }
}
