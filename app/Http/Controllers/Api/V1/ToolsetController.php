<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tool\Actions\CreateToolsetAction;
use App\Domain\Tool\Actions\DeleteToolsetAction;
use App\Domain\Tool\Actions\UpdateToolsetAction;
use App\Domain\Tool\Models\Toolset;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @tags Toolsets
 */
class ToolsetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $toolsets = Toolset::query()
            ->withCount('agents')
            ->orderBy('name')
            ->cursorPaginate(min((int) $request->input('per_page', 20), 100));

        return response()->json($toolsets);
    }

    public function show(Toolset $toolset): JsonResponse
    {
        $toolset->loadCount('agents');

        return response()->json($toolset);
    }

    public function store(Request $request, CreateToolsetAction $action): JsonResponse
    {
        $teamId = $request->user()->current_team_id;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'tool_ids' => 'array',
            'tool_ids.*' => ['uuid', Rule::exists('tools', 'id')->where('team_id', $teamId)],
            'tags' => 'array',
            'tags.*' => 'string|max:50',
        ]);

        $toolset = $action->execute(
            teamId: $teamId,
            name: $validated['name'],
            description: $validated['description'] ?? '',
            toolIds: $validated['tool_ids'] ?? [],
            tags: $validated['tags'] ?? [],
            createdBy: $request->user()->id,
        );

        return response()->json($toolset, 201);
    }

    public function update(Request $request, Toolset $toolset, UpdateToolsetAction $action): JsonResponse
    {
        $teamId = $request->user()->current_team_id;
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'tool_ids' => 'array',
            'tool_ids.*' => ['uuid', Rule::exists('tools', 'id')->where('team_id', $teamId)],
            'tags' => 'array',
            'tags.*' => 'string|max:50',
        ]);

        $toolset = $action->execute($toolset, $validated);

        return response()->json($toolset);
    }

    public function destroy(Toolset $toolset, DeleteToolsetAction $action): JsonResponse
    {
        $action->execute($toolset);

        return response()->json(null, 204);
    }
}
