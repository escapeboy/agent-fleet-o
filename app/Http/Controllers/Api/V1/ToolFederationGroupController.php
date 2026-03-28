<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolFederationGroup;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags Tool Federation Groups
 */
class ToolFederationGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $groups = ToolFederationGroup::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderBy('name')
            ->get()
            ->map(fn (ToolFederationGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'description' => $g->description,
                'tool_ids' => $g->tool_ids,
                'tool_count' => count($g->tool_ids ?? []),
                'is_active' => $g->is_active,
                'created_at' => $g->created_at->toIso8601String(),
                'updated_at' => $g->updated_at->toIso8601String(),
            ]);

        return response()->json(['data' => $groups]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'tool_ids' => 'nullable|array',
            'tool_ids.*' => 'uuid',
            'is_active' => 'boolean',
        ]);

        $teamId = $request->user()->current_team_id;
        $toolIds = $validated['tool_ids'] ?? [];

        // Validate tool IDs belong to this team
        $validIds = Tool::query()
            ->where('team_id', $teamId)
            ->whereIn('id', $toolIds)
            ->pluck('id')
            ->toArray();

        $group = ToolFederationGroup::create([
            'team_id' => $teamId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'tool_ids' => $validIds,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'data' => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'tool_ids' => $group->tool_ids,
                'tool_count' => count($validIds),
                'is_active' => $group->is_active,
            ],
        ], 201);
    }

    public function update(Request $request, ToolFederationGroup $toolFederationGroup): JsonResponse
    {
        abort_if($toolFederationGroup->team_id !== $request->user()->current_team_id, 403);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'tool_ids' => 'nullable|array',
            'tool_ids.*' => 'uuid',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['tool_ids'])) {
            $teamId = $request->user()->current_team_id;
            $validated['tool_ids'] = Tool::query()
                ->where('team_id', $teamId)
                ->whereIn('id', $validated['tool_ids'])
                ->pluck('id')
                ->toArray();
        }

        $toolFederationGroup->update([
            'name' => $validated['name'] ?? $toolFederationGroup->name,
            'description' => $validated['description'] ?? $toolFederationGroup->description,
            'tool_ids' => $validated['tool_ids'] ?? $toolFederationGroup->tool_ids,
            'is_active' => $validated['is_active'] ?? $toolFederationGroup->is_active,
        ]);

        return response()->json([
            'data' => [
                'id' => $toolFederationGroup->id,
                'name' => $toolFederationGroup->name,
                'description' => $toolFederationGroup->description,
                'tool_ids' => $toolFederationGroup->tool_ids,
                'tool_count' => count($toolFederationGroup->tool_ids ?? []),
                'is_active' => $toolFederationGroup->is_active,
            ],
        ]);
    }

    public function destroy(Request $request, ToolFederationGroup $toolFederationGroup): JsonResponse
    {
        abort_if($toolFederationGroup->team_id !== $request->user()->current_team_id, 403);

        $toolFederationGroup->delete();

        return response()->json(['message' => 'Federation group deleted.']);
    }
}
