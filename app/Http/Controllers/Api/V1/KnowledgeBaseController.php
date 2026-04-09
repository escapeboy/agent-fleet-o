<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Knowledge\Actions\CreateKnowledgeBaseAction;
use App\Domain\Knowledge\Actions\DeleteKnowledgeBaseAction;
use App\Domain\Knowledge\Actions\IngestDocumentAction;
use App\Domain\Knowledge\Actions\SearchKnowledgeAction;
use App\Domain\Knowledge\Actions\UpdateKnowledgeBaseAction;
use App\Domain\Knowledge\Models\KnowledgeBase;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * @tags Knowledge Bases
 */
class KnowledgeBaseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $kbs = QueryBuilder::for(KnowledgeBase::class)
            ->allowedFilters([
                AllowedFilter::exact('status'),
                AllowedFilter::exact('agent_id'),
                AllowedFilter::partial('name'),
            ])
            ->allowedSorts(['created_at', 'updated_at', 'name'])
            ->defaultSort('-created_at')
            ->cursorPaginate(min((int) $request->input('per_page', 15), 100));

        return response()->json($kbs);
    }

    public function show(KnowledgeBase $knowledgeBase): JsonResponse
    {
        return response()->json(['data' => $knowledgeBase]);
    }

    public function store(Request $request, CreateKnowledgeBaseAction $action): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'agent_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('agents', 'id')->where('team_id', $request->user()?->current_team_id)],
        ]);

        $kb = $action->execute(
            teamId: $request->user()->current_team_id,
            name: $validated['name'],
            description: $validated['description'] ?? null,
            agentId: $validated['agent_id'] ?? null,
        );

        return response()->json(['data' => $kb], 201);
    }

    public function ingest(Request $request, KnowledgeBase $knowledgeBase, IngestDocumentAction $action): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'min:10'],
            'source_name' => ['sometimes', 'string', 'max:500'],
            'source_type' => ['sometimes', 'in:text,file,url'],
            'reindex' => ['sometimes', 'boolean'],
        ]);

        $action->execute(
            knowledgeBase: $knowledgeBase,
            content: $validated['content'],
            sourceName: $validated['source_name'] ?? 'manual',
            sourceType: $validated['source_type'] ?? 'text',
            reindex: $validated['reindex'] ?? false,
        );

        return response()->json(['message' => 'Ingestion queued.', 'id' => $knowledgeBase->id]);
    }

    public function search(Request $request, SearchKnowledgeAction $action): JsonResponse
    {
        $validated = $request->validate([
            'knowledge_base_id' => ['required', 'uuid', Rule::exists('knowledge_bases', 'id')->where('team_id', $request->user()->current_team_id)],
            'query' => ['required', 'string', 'min:1', 'max:500'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $results = $action->execute(
            knowledgeBaseId: $validated['knowledge_base_id'],
            query: $validated['query'],
            topK: $validated['top_k'] ?? 5,
        );

        return response()->json([
            'data' => $results,
            'total' => count($results),
        ]);
    }

    public function update(Request $request, KnowledgeBase $knowledgeBase, UpdateKnowledgeBaseAction $action): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'agent_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('agents', 'id')->where('team_id', $request->user()?->current_team_id)],
        ]);

        $kb = $action->execute(
            knowledgeBase: $knowledgeBase,
            name: $validated['name'] ?? null,
            updateDescription: array_key_exists('description', $validated),
            description: $validated['description'] ?? null,
            updateAgentId: array_key_exists('agent_id', $validated),
            agentId: $validated['agent_id'] ?? null,
        );

        return response()->json(['data' => $kb]);
    }

    public function destroy(KnowledgeBase $knowledgeBase, DeleteKnowledgeBaseAction $action): JsonResponse
    {
        $action->execute($knowledgeBase);

        return response()->json(['message' => 'Knowledge base deleted.']);
    }
}
