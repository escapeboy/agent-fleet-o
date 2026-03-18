<?php

namespace App\Mcp\Tools\Knowledge;

use App\Domain\Knowledge\Models\KnowledgeBase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class KnowledgeBaseListTool extends Tool
{
    protected string $name = 'knowledge_base_list';

    protected string $description = 'List knowledge bases for the current team. Returns id, name, status, chunk count, and linked agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_id' => $schema->string()
                ->description('Filter by linked agent UUID'),
            'status' => $schema->string()
                ->description('Filter by status: idle, ingesting, ready, error'),
            'limit' => $schema->integer()
                ->description('Max results (default 20)')
                ->default(20),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = auth()->user()?->current_team_id;

        $query = KnowledgeBase::withoutGlobalScopes()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->when($request->get('agent_id'), fn ($q, $v) => $q->where('agent_id', $v))
            ->when($request->get('status'), fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('created_at')
            ->limit(min((int) ($request->get('limit', 20)), 100));

        /** @var Collection<int, KnowledgeBase> $kbs */
        $kbs = $query->get();

        return Response::text(json_encode([
            'count' => $kbs->count(),
            'knowledge_bases' => $kbs->map(fn ($kb) => [
                'id' => $kb->id,
                'name' => $kb->name,
                'description' => $kb->description,
                'status' => $kb->status->value,
                'chunks_count' => $kb->chunks_count,
                'agent_id' => $kb->agent_id,
                'last_ingested_at' => $kb->last_ingested_at?->toIso8601String(),
                'created_at' => $kb->created_at?->toIso8601String(),
            ])->toArray(),
        ]));
    }
}
