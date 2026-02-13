<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Actions\RetryFromStepAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExperimentRequest;
use App\Http\Requests\Api\V1\TransitionExperimentRequest;
use App\Http\Resources\Api\V1\ExperimentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ExperimentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $experiments = QueryBuilder::for(Experiment::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('track'),
                AllowedFilter::partial('title'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'title', 'status'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return ExperimentResource::collection($experiments);
    }

    public function show(Experiment $experiment): ExperimentResource
    {
        return new ExperimentResource($experiment);
    }

    public function store(StoreExperimentRequest $request, CreateExperimentAction $action): JsonResponse
    {
        $experiment = $action->execute(
            userId: $request->user()->id,
            title: $request->title,
            thesis: $request->thesis,
            track: $request->track,
            budgetCapCredits: $request->input('budget_cap_credits', 10000),
            maxIterations: $request->input('max_iterations', 10),
            maxOutboundCount: $request->input('max_outbound_count', 100),
            constraints: $request->input('constraints', []),
            successCriteria: $request->input('success_criteria', []),
            teamId: $request->user()->current_team_id,
            workflowId: $request->input('workflow_id'),
        );

        return (new ExperimentResource($experiment))
            ->response()
            ->setStatusCode(201);
    }

    public function transition(
        TransitionExperimentRequest $request,
        Experiment $experiment,
        TransitionExperimentAction $action,
    ): ExperimentResource {
        $experiment = $action->execute(
            experiment: $experiment,
            toState: ExperimentStatus::from($request->status),
            reason: $request->reason,
            actorId: $request->user()->id,
        );

        return new ExperimentResource($experiment);
    }

    public function pause(Request $request, Experiment $experiment, PauseExperimentAction $action): ExperimentResource
    {
        $experiment = $action->execute(
            experiment: $experiment,
            actorId: $request->user()->id,
            reason: $request->input('reason', 'Paused via API'),
        );

        return new ExperimentResource($experiment);
    }

    public function resume(Request $request, Experiment $experiment, ResumeExperimentAction $action): ExperimentResource
    {
        $experiment = $action->execute(
            experiment: $experiment,
            actorId: $request->user()->id,
        );

        return new ExperimentResource($experiment);
    }

    public function retry(Request $request, Experiment $experiment, RetryExperimentAction $action): ExperimentResource
    {
        $experiment = $action->execute(
            experiment: $experiment,
            actorId: $request->user()->id,
        );

        return new ExperimentResource($experiment);
    }

    public function kill(Request $request, Experiment $experiment, KillExperimentAction $action): ExperimentResource
    {
        $experiment = $action->execute(
            experiment: $experiment,
            actorId: $request->user()->id,
            reason: $request->input('reason', 'Killed via API'),
        );

        return new ExperimentResource($experiment);
    }

    public function retryFromStep(Request $request, Experiment $experiment, RetryFromStepAction $action): JsonResponse
    {
        $request->validate([
            'step_id' => ['required', 'uuid'],
        ]);

        $step = PlaybookStep::where('experiment_id', $experiment->id)
            ->where('id', $request->step_id)
            ->firstOrFail();

        $action->execute($experiment, $step);

        return response()->json(['message' => 'Retry from step initiated.'], 202);
    }

    public function steps(Experiment $experiment): JsonResponse
    {
        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get([
                'id', 'experiment_id', 'workflow_node_id', 'agent_id', 'skill_id', 'crew_id',
                'order', 'status', 'input_prompt', 'output', 'error_message',
                'duration_ms', 'cost_credits', 'started_at', 'completed_at',
            ]);

        return response()->json(['data' => $steps]);
    }
}
