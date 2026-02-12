<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tool\Actions\CreateToolAction;
use App\Domain\Tool\Actions\DeleteToolAction;
use App\Domain\Tool\Actions\UpdateToolAction;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ToolResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rules\Enum;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ToolController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tools = QueryBuilder::for(Tool::class)
            ->withCount('agents')
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('type'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate($request->input('per_page', 15));

        return ToolResource::collection($tools);
    }

    public function show(Tool $tool): ToolResource
    {
        $tool->loadCount('agents');

        return new ToolResource($tool);
    }

    public function store(Request $request, CreateToolAction $action): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(ToolType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'transport_config' => ['required', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'tool_definitions' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        $tool = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $request->name,
            type: ToolType::from($request->type),
            description: $request->input('description'),
            transportConfig: $request->input('transport_config'),
            credentials: $request->input('credentials'),
            toolDefinitions: $request->input('tool_definitions'),
            settings: $request->input('settings', []),
        );

        $tool->loadCount('agents');

        return (new ToolResource($tool))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Tool $tool, UpdateToolAction $action): ToolResource
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(ToolStatus::class)],
            'transport_config' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'tool_definitions' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);

        $action->execute(
            $tool,
            name: $request->input('name'),
            description: $request->input('description'),
            status: $request->has('status') ? ToolStatus::from($request->status) : null,
            transportConfig: $request->input('transport_config'),
            credentials: $request->input('credentials'),
            toolDefinitions: $request->input('tool_definitions'),
            settings: $request->input('settings'),
        );

        $tool->refresh()->loadCount('agents');

        return new ToolResource($tool);
    }

    public function destroy(Tool $tool, DeleteToolAction $action): JsonResponse
    {
        $action->execute($tool);

        return response()->json(['message' => 'Tool deleted.']);
    }
}
