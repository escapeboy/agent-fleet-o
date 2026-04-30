<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Actions\DeleteToolAction;
use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreToolRequest;
use App\Http\Requests\Api\V1\UpdateToolRequest;
use App\Http\Resources\Api\V1\ToolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Tools
 */
class ToolController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tools = QueryBuilder::for(Tool::class)
            ->withCount('agents')
            ->allowedFilters(
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::partial('name'),
            )
            ->allowedSorts('created_at', 'updated_at', 'name')
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return ToolResource::collection($tools);
    }

    public function show(Tool $tool): ToolResource
    {
        $tool->loadCount('agents');

        return new ToolResource($tool);
    }

    public function store(StoreToolRequest $request, CreateToolAction $action): JsonResponse
    {
        $tool = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            type: ToolType::from($request->type),
            description: $request->input('description', ''),
            transportConfig: $request->input('transport_config', []),
            credentials: $request->input('credentials', []),
            toolDefinitions: $request->input('tool_definitions', []),
            settings: $request->input('settings', []),
            credentialId: $request->input('credential_id'),
        );

        if ($request->has('risk_level') && $request->risk_level) {
            $tool->update(['risk_level' => ToolRiskLevel::from($request->risk_level)]);
        }

        $tool->loadCount('agents');

        return (new ToolResource($tool))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateToolRequest $request, Tool $tool, UpdateToolAction $action): ToolResource
    {

        $action->execute(
            $tool,
            name: $request->input('name'),
            description: $request->input('description'),
            transportConfig: $request->input('transport_config'),
            credentials: $request->input('credentials'),
            toolDefinitions: $request->input('tool_definitions'),
            settings: $request->input('settings'),
            riskLevel: $request->has('risk_level') && $request->risk_level
                ? ToolRiskLevel::from($request->risk_level)
                : null,
            credentialId: $request->input('credential_id'),
            clearCredentialId: (bool) $request->input('clear_credential_id', false),
        );

        if ($request->has('status')) {
            $tool->update(['status' => ToolStatus::from($request->status)]);
        }

        $tool->refresh()->loadCount('agents');

        return new ToolResource($tool);
    }

    /**
     * @response 200 {"message": "Tool deleted."}
     */
    public function destroy(Tool $tool, DeleteToolAction $action): JsonResponse
    {
        $action->execute($tool);

        return response()->json(['message' => 'Tool deleted.']);
    }
}
