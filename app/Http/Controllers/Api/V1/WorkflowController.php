<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Workflow\Actions\CreateWorkflowAction;
use App\Domain\Workflow\Actions\DeleteWorkflowAction;
use App\Domain\Workflow\Actions\EstimateWorkflowCostAction;
use App\Domain\Workflow\Actions\ExportWorkflowAction;
use App\Domain\Workflow\Actions\ImportWorkflowAction;
use App\Domain\Workflow\Actions\UpdateWorkflowAction;
use App\Domain\Workflow\Actions\ValidateWorkflowGraphAction;
use App\Domain\Workflow\Models\Workflow;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WorkflowResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Workflows
 */
class WorkflowController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $workflows = QueryBuilder::for(Workflow::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name', 'version'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return WorkflowResource::collection($workflows);
    }

    public function show(Workflow $workflow): WorkflowResource
    {
        $workflow->load(['nodes', 'edges']);

        return new WorkflowResource($workflow);
    }

    public function store(Request $request, CreateWorkflowAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'max_loop_iterations' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'budget_cap_credits' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'nodes' => ['sometimes', 'array'],
            'nodes.*.type' => ['required_with:nodes', 'in:start,end,agent,conditional'],
            'nodes.*.label' => ['required_with:nodes', 'string', 'max:100'],
            'edges' => ['sometimes', 'array'],
            'checkpoint_mode' => ['sometimes', 'string', 'in:sync,async,exit'],
        ]);

        $settings = [];
        if ($request->has('checkpoint_mode')) {
            $settings['checkpoint_mode'] = $request->input('checkpoint_mode');
        }

        $workflow = $action->execute(
            userId: $request->user()->id,
            name: $request->name,
            description: $request->description,
            nodes: $request->input('nodes', []),
            edges: $request->input('edges', []),
            maxLoopIterations: $request->input('max_loop_iterations', 5),
            teamId: $request->user()->current_team_id,
            settings: $settings,
            budgetCapCredits: $request->input('budget_cap_credits') !== null
                ? (int) $request->input('budget_cap_credits')
                : null,
        );

        return (new WorkflowResource($workflow->load(['nodes', 'edges'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Workflow $workflow, UpdateWorkflowAction $action): WorkflowResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'max_loop_iterations' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'budget_cap_credits' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'checkpoint_mode' => ['sometimes', 'string', 'in:sync,async,exit'],
        ]);

        $settings = null;
        if ($request->has('checkpoint_mode')) {
            $settings = ['checkpoint_mode' => $request->input('checkpoint_mode')];
        }

        $workflow = $action->execute(
            workflow: $workflow,
            name: $request->input('name'),
            description: $request->input('description'),
            maxLoopIterations: $request->input('max_loop_iterations'),
            settings: $settings,
            budgetCapCredits: $request->has('budget_cap_credits') && $request->input('budget_cap_credits') !== null
                ? (int) $request->input('budget_cap_credits')
                : null,
            clearBudgetCap: $request->has('budget_cap_credits') && $request->input('budget_cap_credits') === null,
        );

        return new WorkflowResource($workflow);
    }

    /**
     * @response 200 {"message": "Workflow deleted."}
     */
    public function destroy(Workflow $workflow, DeleteWorkflowAction $action): JsonResponse
    {
        $action->execute($workflow);

        return response()->json(['message' => 'Workflow deleted.']);
    }

    public function saveGraph(Request $request, Workflow $workflow, UpdateWorkflowAction $action): WorkflowResource
    {
        $request->validate([
            'nodes' => ['required', 'array'],
            'nodes.*.type' => ['required', 'in:start,end,agent,conditional'],
            'nodes.*.label' => ['required', 'string', 'max:100'],
            'edges' => ['sometimes', 'array'],
        ]);

        $workflow = $action->execute(
            workflow: $workflow,
            nodes: $request->nodes,
            edges: $request->input('edges', []),
        );

        return new WorkflowResource($workflow->load(['nodes', 'edges']));
    }

    /**
     * @response 200 {"valid": true, "errors": []}
     */
    public function validateGraph(Workflow $workflow, ValidateWorkflowGraphAction $action): JsonResponse
    {
        $result = $action->execute($workflow);

        return response()->json($result);
    }

    /**
     * @response 200 {"message": "Workflow activated.", "data": {}}
     * @response 422 {"message": "Workflow graph is invalid.", "errors": ["Missing start node."]}
     */
    public function activate(Workflow $workflow, ValidateWorkflowGraphAction $action): JsonResponse
    {
        $result = $action->execute($workflow, activateIfValid: true);

        if (! $result['valid']) {
            return response()->json([
                'message' => 'Workflow graph is invalid.',
                'errors' => $result['errors'],
            ], 422);
        }

        return response()->json([
            'message' => 'Workflow activated.',
            'data' => new WorkflowResource($workflow->fresh()),
        ]);
    }

    public function duplicate(Workflow $workflow, CreateWorkflowAction $action): JsonResponse
    {
        $newWorkflow = $action->execute(
            userId: request()->user()->id,
            name: $workflow->name.' (copy)',
            description: $workflow->description,
            nodes: $workflow->nodes->map(fn ($n) => [
                'type' => $n->type->value,
                'label' => $n->label,
                'agent_id' => $n->agent_id,
                'skill_id' => $n->skill_id,
                'position_x' => $n->position_x,
                'position_y' => $n->position_y,
                'config' => $n->config,
            ])->toArray(),
            edges: $workflow->edges->map(fn ($e) => [
                'source_node_index' => $workflow->nodes->search(fn ($n) => $n->id === $e->source_node_id),
                'target_node_index' => $workflow->nodes->search(fn ($n) => $n->id === $e->target_node_id),
                'condition' => $e->condition,
                'label' => $e->label,
                'is_default' => $e->is_default,
                'sort_order' => $e->sort_order,
            ])->toArray(),
            maxLoopIterations: $workflow->max_loop_iterations,
            teamId: request()->user()->current_team_id,
            settings: $workflow->settings ?? [],
        );

        return (new WorkflowResource($newWorkflow->load(['nodes', 'edges'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @response 200 {"estimated_cost_credits": 150}
     */
    public function estimateCost(Workflow $workflow, EstimateWorkflowCostAction $action): JsonResponse
    {
        $credits = $action->execute($workflow);

        return response()->json([
            'estimated_cost_credits' => $credits,
        ]);
    }

    /**
     * Export a workflow to JSON or YAML format.
     *
     * @response 200 {"format_version": "2.0", "workflow": {}, "nodes": [], "edges": [], "references": {}}
     */
    public function export(Request $request, Workflow $workflow, ExportWorkflowAction $action): JsonResponse|Response
    {
        $format = $request->query('format', 'json');
        if (! in_array($format, ['json', 'yaml'])) {
            return response()->json(['message' => 'Format must be json or yaml.'], 422);
        }

        $result = $action->execute($workflow, $format);

        if ($format === 'yaml') {
            return response($result, 200, ['Content-Type' => 'text/yaml']);
        }

        return response()->json($result);
    }

    /**
     * Import a workflow from a JSON or YAML file.
     *
     * @response 201 {"workflow_id": "...", "unresolved_references": [], "checksum_valid": true}
     */
    public function import(Request $request, ImportWorkflowAction $action): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:1024'],
        ]);

        $content = file_get_contents($request->file('file')->getRealPath());

        try {
            $result = $action->execute(
                data: $content,
                teamId: $request->user()->current_team_id,
                userId: $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $workflow = $result['workflow'];

        return response()->json([
            'workflow_id' => $workflow->id,
            'workflow_name' => $workflow->name,
            'status' => 'draft',
            'checksum_valid' => $result['checksum_valid'],
            'unresolved_references' => $result['unresolved_references'],
            'data' => new WorkflowResource($workflow->load(['nodes', 'edges'])),
        ], 201);
    }
}
