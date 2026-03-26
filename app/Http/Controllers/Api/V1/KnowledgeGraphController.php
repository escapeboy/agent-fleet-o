<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\KnowledgeGraph\Actions\AddKnowledgeFactAction;
use App\Domain\KnowledgeGraph\Actions\InvalidateKgFactAction;
use App\Domain\KnowledgeGraph\Actions\SearchKgFactsAction;
use App\Domain\KnowledgeGraph\Models\KgEdge;
use App\Domain\Signal\Models\Entity;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\KgEdgeResource;
use App\Http\Resources\Api\V1\KgEntityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Knowledge Graph
 */
class KnowledgeGraphController extends Controller
{
    /**
     * Semantic search over knowledge graph facts.
     *
     * @response 200 {"data": [], "query": "...", "total": 0}
     */
    public function search(Request $request, SearchKgFactsAction $action): JsonResponse
    {
        $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'relation_type' => ['sometimes', 'string', 'max:80'],
            'entity_type' => ['sometimes', 'in:person,company,location,date,product,topic'],
            'include_history' => ['sometimes', 'boolean'],
            'threshold' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $facts = $action->execute(
            teamId: $request->user()->current_team_id,
            query: $request->input('query'),
            relationType: $request->input('relation_type'),
            entityType: $request->input('entity_type'),
            includeHistory: (bool) $request->input('include_history', false),
            threshold: (float) $request->input('threshold', 0.7),
            limit: (int) $request->input('limit', 10),
        );

        return response()->json([
            'data' => KgEdgeResource::collection($facts),
            'query' => $request->input('query'),
            'total' => $facts->count(),
        ]);
    }

    /**
     * List facts for a specific entity (by name + type).
     */
    public function entityFacts(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['sometimes', 'in:person,company,location,date,product,topic'],
            'include_history' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $teamId = $request->user()->current_team_id;
        $includeHistory = (bool) $request->input('include_history', false);

        $entity = Entity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('canonical_name', strtolower(trim($request->input('name'))))
            ->when($request->has('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->first();

        if (! $entity) {
            return KgEdgeResource::collection(collect());
        }

        $facts = KgEdge::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where(fn ($q) => $q
                ->where('source_entity_id', $entity->id)
                ->orWhere('target_entity_id', $entity->id),
            )
            ->when(! $includeHistory, fn ($q) => $q->whereNull('invalid_at'))
            ->with(['sourceEntity', 'targetEntity'])
            ->orderByDesc('valid_at')
            ->limit((int) $request->input('limit', 20))
            ->get();

        return KgEdgeResource::collection($facts);
    }

    /**
     * Add a new knowledge fact.
     *
     * @response 201 {"data": {...}}
     */
    public function store(Request $request, AddKnowledgeFactAction $action): JsonResponse
    {
        $request->validate([
            'source_name' => ['required', 'string', 'max:255'],
            'source_type' => ['required', 'in:person,company,location,date,product,topic'],
            'relation_type' => ['required', 'string', 'max:80'],
            'target_name' => ['required', 'string', 'max:255'],
            'target_type' => ['required', 'in:person,company,location,date,product,topic'],
            'fact' => ['required', 'string', 'min:1', 'max:2000'],
            'attributes' => ['sometimes', 'array'],
        ]);

        $edge = $action->execute(
            teamId: $request->user()->current_team_id,
            sourceName: $request->input('source_name'),
            sourceType: $request->input('source_type'),
            relationType: $request->input('relation_type'),
            targetName: $request->input('target_name'),
            targetType: $request->input('target_type'),
            fact: $request->input('fact'),
            attributes: $request->input('attributes', []),
        );

        return response()->json(['data' => new KgEdgeResource($edge->load(['sourceEntity', 'targetEntity']))], 201);
    }

    /**
     * Invalidate a knowledge graph fact (soft invalidation via invalid_at).
     *
     * @response 200 {"message": "Fact invalidated.", "id": "..."}
     */
    public function destroy(Request $request, string $factId, InvalidateKgFactAction $action): JsonResponse
    {
        $edge = KgEdge::withoutGlobalScopes()
            ->where('id', $factId)
            ->where('team_id', $request->user()->current_team_id)
            ->first();

        if (! $edge) {
            return response()->json(['message' => 'KG fact not found.'], 404);
        }

        if ($edge->invalid_at !== null) {
            return response()->json(['message' => 'Already invalidated.', 'id' => $edge->id]);
        }

        $action->execute($edge);

        return response()->json(['message' => 'Fact invalidated.', 'id' => $edge->id]);
    }

    /**
     * List entities in the knowledge graph.
     */
    public function entities(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['sometimes', 'in:person,company,location,date,product,topic'],
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $entities = Entity::withoutGlobalScopes()
            ->where('team_id', $request->user()->current_team_id)
            ->when($request->has('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->has('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->input('search').'%'))
            ->orderByDesc('mention_count')
            ->cursorPaginate(min((int) $request->input('per_page', 20), 100));

        return KgEntityResource::collection($entities);
    }
}
