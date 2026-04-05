<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Evaluation\Actions\CreateFlowEvaluationDatasetAction;
use App\Domain\Evaluation\Actions\RunFlowEvaluationAction;
use App\Domain\Evaluation\Models\EvaluationDataset;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evaluation\Models\EvaluationRunResult;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Flow Evaluations
 */
class FlowEvaluationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $datasets = QueryBuilder::for(EvaluationDataset::class)
            ->allowedFilters(['name'])
            ->allowedSorts(['created_at', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($datasets);
    }

    public function show(EvaluationDataset $dataset): JsonResponse
    {
        $dataset->load(['workflow', 'runs' => fn ($q) => $q->latest()->limit(5)]);

        return response()->json($dataset);
    }

    public function store(Request $request, CreateFlowEvaluationDatasetAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'workflow_id' => ['sometimes', 'nullable', 'uuid', 'exists:workflows,id'],
            'rows' => ['sometimes', 'array', 'max:500'],
            'rows.*.input' => ['required_with:rows', 'array'],
            'rows.*.expected_output' => ['sometimes', 'nullable', 'string'],
            'rows.*.metadata' => ['sometimes', 'nullable', 'array'],
        ]);

        $dataset = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->input('name'),
            description: $request->input('description'),
            workflowId: $request->input('workflow_id'),
            rows: $request->input('rows', []),
        );

        return response()->json($dataset, 201);
    }

    /**
     * @response 201 {"id": "uuid", "status": "pending", "dataset_id": "uuid"}
     */
    public function run(Request $request, EvaluationDataset $dataset, RunFlowEvaluationAction $action): JsonResponse
    {
        $request->validate([
            'workflow_id' => ['required', 'uuid', 'exists:workflows,id'],
            'judge_model' => ['sometimes', 'nullable', 'string', 'max:128'],
            'judge_prompt' => ['sometimes', 'nullable', 'string'],
        ]);

        $run = $action->execute(
            dataset: $dataset,
            workflowId: $request->input('workflow_id'),
            judgeModel: $request->input('judge_model'),
            judgePrompt: $request->input('judge_prompt'),
        );

        return response()->json($run, 201);
    }

    public function runs(EvaluationDataset $dataset): JsonResponse
    {
        $runs = EvaluationRun::where('dataset_id', $dataset->id)
            ->with('workflow')
            ->latest()
            ->cursorPaginate(20);

        return response()->json($runs);
    }

    public function runShow(EvaluationRun $run): JsonResponse
    {
        $run->load(['dataset', 'workflow']);

        return response()->json($run);
    }

    public function runResults(EvaluationRun $run): JsonResponse
    {
        $results = EvaluationRunResult::where('run_id', $run->id)
            ->with('row')
            ->latest('created_at')
            ->cursorPaginate(50);

        return response()->json($results);
    }
}
