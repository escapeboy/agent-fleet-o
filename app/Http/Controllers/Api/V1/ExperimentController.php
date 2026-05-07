<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\ExportTrajectoryAction;
use App\Domain\Experiment\Actions\KillExperimentAction;
use App\Domain\Experiment\Actions\PauseExperimentAction;
use App\Domain\Experiment\Actions\ResumeExperimentAction;
use App\Domain\Experiment\Actions\ResumeFromCheckpointAction;
use App\Domain\Experiment\Actions\RetryExperimentAction;
use App\Domain\Experiment\Actions\RetryFromStepAction;
use App\Domain\Experiment\Actions\SteerExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Experiment\Models\WorkflowSnapshot;
use App\Http\Controllers\Api\V1\Concerns\DocumentsResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreExperimentRequest;
use App\Http\Requests\Api\V1\TransitionExperimentRequest;
use App\Http\Resources\Api\V1\ExperimentResource;
use App\Http\Resources\Api\V1\PlaybookStepResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @tags Experiments
 */
class ExperimentController extends Controller
{
    use DocumentsResponses;

    public function index(Request $request): AnonymousResourceCollection
    {
        $experiments = QueryBuilder::for(Experiment::class)
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('track'),
                AllowedFilter::partial('title'),
            )
            ->allowedSorts('created_at', 'updated_at', 'title', 'status')
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

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

        return (new ExperimentResource($experiment))->invalidates('experiments');
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

    public function steer(Request $request, Experiment $experiment, SteerExperimentAction $action): ExperimentResource
    {
        $request->validate([
            'message' => 'required|string|min:1|max:2000',
        ]);

        $experiment = $action->execute(
            experiment: $experiment,
            message: $request->input('message'),
            userId: $request->user()?->id,
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

    /**
     * @response 202 {"message": "Retry from step initiated."}
     * @response 422 {"message": "Validation error.", "errors": {"step_id": ["The step id field is required."]}}
     */
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

    /**
     * Resume an experiment from its most recent checkpoint without resetting progress.
     *
     * @response 202 {"resumed": true, "step_id": "uuid", "message": "Resumed from checkpoint at step #2."}
     * @response 422 {"resumed": false, "step_id": null, "message": "No checkpoint data found."}
     */
    public function resumeFromCheckpoint(Experiment $experiment, ResumeFromCheckpointAction $action): JsonResponse
    {
        $result = $action->execute($experiment);

        return response()->json($result, $result['resumed'] ? 202 : 422);
    }

    public function steps(Experiment $experiment): AnonymousResourceCollection
    {
        $steps = PlaybookStep::where('experiment_id', $experiment->id)
            ->orderBy('order')
            ->get();

        return PlaybookStepResource::collection($steps);
    }

    /**
     * List time-travel snapshots for an experiment.
     *
     * @response 200 {"data": [{"id": "uuid", "sequence": 0, "event_type": "step_started", ...}]}
     */
    public function snapshots(Request $request, Experiment $experiment): JsonResponse
    {
        $request->validate([
            'event_type' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = WorkflowSnapshot::where('experiment_id', $experiment->id)
            ->orderBy('sequence');

        if ($request->has('event_type')) {
            $query->where('event_type', $request->input('event_type'));
        }

        $snapshots = $query->limit($request->input('limit', 50))->get();

        return response()->json([
            'data' => $snapshots->map(fn ($s) => [
                'id' => $s->id,
                'sequence' => $s->sequence,
                'event_type' => $s->event_type,
                'workflow_node_id' => $s->workflow_node_id,
                'duration_from_start_ms' => $s->duration_from_start_ms,
                'graph_state' => $s->graph_state,
                'step_input' => $s->step_input,
                'step_output' => $s->step_output,
                'metadata' => $s->metadata,
                'created_at' => $s->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get detailed cost breakdown for an experiment.
     *
     * @response 200 {"experiment_id": "uuid", "total_cost_credits": 120, "by_stage": [], "by_model": []}
     */
    public function cost(Experiment $experiment): JsonResponse
    {
        $runs = AiRun::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('team_id', $experiment->team_id)
            ->with('experimentStage:id,stage_type')
            ->get();

        $totalCost = $runs->sum('cost_credits');
        $totalTokensIn = $runs->sum('input_tokens');
        $totalTokensOut = $runs->sum('output_tokens');
        $cachedCount = $runs->where('cost_credits', 0)->where('status', 'completed')->count();

        $nonCachedRuns = $runs->where('cost_credits', '>', 0);
        $avgCost = $nonCachedRuns->isNotEmpty() ? $nonCachedRuns->avg('cost_credits') : 0;
        $estimatedSavings = (int) round($cachedCount * $avgCost);

        $byStage = $runs
            ->filter(fn ($r) => $r->cost_credits > 0)
            ->groupBy(fn ($r) => $r->experimentStage->stage_type ?? 'unknown')
            ->map(fn ($group) => [
                'runs' => $group->count(),
                'cost_credits' => $group->sum('cost_credits'),
                'tokens_in' => $group->sum('input_tokens'),
                'tokens_out' => $group->sum('output_tokens'),
            ])
            ->sortByDesc('cost_credits')
            ->values()
            ->toArray();

        $byModel = $runs
            ->filter(fn ($r) => $r->cost_credits > 0)
            ->groupBy(fn ($r) => "{$r->provider}/{$r->model}")
            ->map(fn ($group, $key) => [
                'provider_model' => $key,
                'runs' => $group->count(),
                'cost_credits' => $group->sum('cost_credits'),
                'tokens_in' => $group->sum('input_tokens'),
                'tokens_out' => $group->sum('output_tokens'),
                'avg_latency_ms' => (int) $group->avg('latency_ms'),
            ])
            ->sortByDesc('cost_credits')
            ->values()
            ->toArray();

        return response()->json([
            'experiment_id' => $experiment->id,
            'experiment_title' => $experiment->title,
            'total_cost_credits' => $totalCost,
            'total_tokens_in' => $totalTokensIn,
            'total_tokens_out' => $totalTokensOut,
            'total_runs' => $runs->count(),
            'cached_runs' => $cachedCount,
            'estimated_savings_credits' => $estimatedSavings,
            'by_stage' => $byStage,
            'by_model' => $byModel,
        ]);
    }

    /**
     * Export an experiment's execution trajectory as CSV or JSONL.
     *
     * @response 200 scenario="csv" {"description":"CSV file download"}
     */
    public function trajectory(Request $request, Experiment $experiment): StreamedResponse
    {
        $request->validate(['format' => 'nullable|string|in:csv,jsonl']);

        if ($experiment->team_id !== $request->user()->current_team_id) {
            abort(403);
        }

        $format = $request->input('format', 'csv');
        $result = (new ExportTrajectoryAction)->execute($experiment, $format);

        return response()->streamDownload(
            fn () => print ($result['content']),
            $result['filename'],
            ['Content-Type' => $result['mime']],
        );
    }
}
