<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\KnowledgeGraph\Models\KgCommunity;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class KgCommunityListTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'kg_community_list';

    protected string $description = 'List knowledge graph communities (entity clusters with LLM-generated label and summary), largest first. Optional label search. Returns label, size, top_entities and summary per community. Mirrors the KG Communities page.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()
                ->description('Case-insensitive substring filter on community label'),
            'page' => $schema->integer()
                ->description('Page number (default: 1)')
                ->default(1),
            'per_page' => $schema->integer()
                ->description('Results per page (default: 20, max: 100)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);
        $page = max((int) $request->get('page', 1), 1);

        $query = KgCommunity::query()
            ->where('team_id', $teamId);

        if ($search = $request->get('search')) {
            $query->whereRaw('lower(label) like ?', ['%'.mb_strtolower((string) $search).'%']);
        }

        $communities = $query
            ->orderByDesc('size')
            ->paginate($perPage, ['*'], 'page', $page);

        return Response::text(json_encode([
            'communities' => collect($communities->items())->map(fn (KgCommunity $c) => [
                'id' => $c->id,
                'label' => $c->label,
                'size' => $c->size,
                'top_entities' => $c->top_entities,
                'summary' => $c->summary,
            ])->values()->toArray(),
            'pagination' => [
                'page' => $communities->currentPage(),
                'per_page' => $communities->perPage(),
                'total' => $communities->total(),
                'last_page' => $communities->lastPage(),
            ],
        ]));
    }
}
