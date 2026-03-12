<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Models\Memory;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MemoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Memory
 */
class MemoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $memories = QueryBuilder::for(Memory::class)
            ->allowedFilters([
                AllowedFilter::exact('agent_id'),
                AllowedFilter::exact('project_id'),
                AllowedFilter::exact('source_type'),
                AllowedFilter::partial('content'),
            ])
            ->allowedSorts(['created_at', 'confidence'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return MemoryResource::collection($memories);
    }

    public function show(Memory $memory): MemoryResource
    {
        return new MemoryResource($memory);
    }

    /**
     * @response 200 {"data": [], "query": "...", "total": 0}
     */
    public function search(Request $request, RetrieveRelevantMemoriesAction $action): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'agent_id' => ['sometimes', 'string'],
            'project_id' => ['sometimes', 'nullable', 'string'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'scope' => ['sometimes', 'in:agent,team,project'],
        ]);

        $memories = $action->execute(
            agentId: $request->input('agent_id', ''),
            query: $request->input('query'),
            projectId: $request->input('project_id'),
            topK: $request->input('top_k'),
            threshold: $request->input('threshold'),
            scope: $request->input('scope', $request->has('agent_id') ? 'agent' : 'team'),
            teamId: $request->user()->current_team_id,
        );

        return response()->json([
            'data' => MemoryResource::collection($memories),
            'query' => $request->input('query'),
            'total' => $memories->count(),
        ]);
    }

    /**
     * @response 200 {"total": 0, "by_source_type": {}, "by_agent": {}}
     */
    public function stats(Request $request): JsonResponse
    {
        $teamId = $request->user()->current_team_id;

        $total = Memory::where('team_id', $teamId)->count();

        $bySourceType = Memory::where('team_id', $teamId)
            ->selectRaw('source_type, count(*) as count')
            ->groupBy('source_type')
            ->pluck('count', 'source_type');

        $byAgent = Memory::where('team_id', $teamId)
            ->whereNotNull('agent_id')
            ->selectRaw('agent_id, count(*) as count')
            ->groupBy('agent_id')
            ->pluck('count', 'agent_id');

        return response()->json([
            'total' => $total,
            'by_source_type' => $bySourceType,
            'by_agent' => $byAgent,
        ]);
    }

    public function store(Request $request, StoreMemoryAction $action): JsonResponse
    {
        $request->validate([
            'agent_id' => ['required', 'string'],
            'content' => ['required', 'string', 'min:1'],
            'source_type' => ['sometimes', 'string', 'max:64'],
            'project_id' => ['sometimes', 'nullable', 'string'],
            'source_id' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'array'],
            'tags' => ['sometimes', 'array'],
            'confidence' => ['sometimes', 'numeric', 'min:0', 'max:1'],
        ]);

        $memories = $action->execute(
            teamId: $request->user()->current_team_id,
            agentId: $request->input('agent_id'),
            content: $request->input('content'),
            sourceType: $request->input('source_type', 'api'),
            projectId: $request->input('project_id'),
            sourceId: $request->input('source_id'),
            metadata: $request->input('metadata', []),
            confidence: (float) $request->input('confidence', 1.0),
            tags: $request->input('tags', []),
        );

        return response()->json([
            'data' => MemoryResource::collection(collect($memories)),
            'count' => count($memories),
        ], 201);
    }

    /**
     * @response 200 {"message": "Memory deleted."}
     */
    public function destroy(Memory $memory): JsonResponse
    {
        $memory->delete();

        return response()->json(['message' => 'Memory deleted.']);
    }
}
