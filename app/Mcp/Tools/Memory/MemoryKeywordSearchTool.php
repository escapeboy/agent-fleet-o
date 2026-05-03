<?php

namespace App\Mcp\Tools\Memory;

use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * A-RAG keyword retrieval — exact lexical match over memories.content via PostgreSQL FTS.
 *
 * Use when the agent needs passages that mention specific terms verbatim.
 * For paraphrases / semantic similarity, use memory_unified_search instead.
 * For full content of a known chunk + its neighbours, use memory_chunk_read.
 */
#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class MemoryKeywordSearchTool extends Tool
{
    protected string $name = 'memory_keyword_search';

    protected string $description = 'Exact keyword / lexical search across memory content using PostgreSQL full-text search (ts_rank). Use when you need passages that mention specific terms verbatim. For paraphrases or semantic similarity, use memory_unified_search. For surrounding context, follow up with memory_chunk_read.';

    public function schema(JsonSchema $schema): array
    {
        $teamId = (string) (app('mcp.team_id') ?? auth()->user()->current_team_id ?? '');

        return [
            'query' => $schema->string()
                ->required()
                ->description('Free-text query. Tokenized via Postgres "english" config (stemmed + stopword-stripped).'),
            'agent_id' => $schema->string()
                ->description('Optional agent UUID filter — must belong to the current team.'),
            'topic' => $schema->string()
                ->description('Optional topic slug filter (e.g. "auth_migration").'),
            'limit' => $schema->integer()
                ->description('Max results (1–100, default 10).')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'no_team_resolved']));
        }

        $validated = $request->validate([
            'query' => 'required|string|min:2|max:500',
            'agent_id' => "nullable|uuid|exists:agents,id,team_id,{$teamId}",
            'topic' => 'nullable|string|max:100',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $limit = (int) ($validated['limit'] ?? 10);

        if (DB::getDriverName() === 'pgsql') {
            $rows = $this->ftsSearch($teamId, $validated, $limit);
        } else {
            $rows = $this->ilikeFallback($teamId, $validated, $limit);
        }

        return Response::text(json_encode([
            'count' => $rows->count(),
            'matches' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'rank' => isset($r->rank) ? round((float) $r->rank, 4) : null,
                'snippet' => mb_substr((string) ($r->content ?? ''), 0, 200),
                'topic' => $r->topic,
                'agent_id' => $r->agent_id,
                'created_at' => $r->created_at,
            ])->values(),
        ]));
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function ftsSearch(string $teamId, array $v, int $limit): Collection
    {
        $query = DB::table('memories')
            ->select(['id', 'content', 'topic', 'agent_id', 'created_at',
                DB::raw("ts_rank(content_tsv, plainto_tsquery('english', ?)) AS rank")])
            ->addBinding($v['query'], 'select')
            ->where('team_id', $teamId)
            ->whereRaw("content_tsv @@ plainto_tsquery('english', ?)", [$v['query']]);

        if (! empty($v['agent_id'])) {
            $query->where('agent_id', $v['agent_id']);
        }
        if (! empty($v['topic'])) {
            $query->where('topic', $v['topic']);
        }

        return $query->orderByDesc('rank')->limit($limit)->get();
    }

    /**
     * @param  array<string, mixed>  $v
     */
    private function ilikeFallback(string $teamId, array $v, int $limit): Collection
    {
        $tokens = array_values(array_filter(preg_split('/\s+/', (string) $v['query']) ?: []));

        $query = DB::table('memories')
            ->select(['id', 'content', 'topic', 'agent_id', 'created_at'])
            ->where('team_id', $teamId);

        // Each token must be present (AND), substring match.
        foreach ($tokens as $token) {
            $query->where('content', 'like', '%'.addcslashes($token, '%_').'%');
        }

        if (! empty($v['agent_id'])) {
            $query->where('agent_id', $v['agent_id']);
        }
        if (! empty($v['topic'])) {
            $query->where('topic', $v['topic']);
        }

        return $query->orderByDesc('created_at')->limit($limit)->get();
    }
}
