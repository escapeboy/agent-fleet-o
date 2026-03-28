<?php

namespace App\Mcp\Tools\Cache;

use App\Infrastructure\AI\Models\SemanticCacheEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SemanticCacheStatsTool extends Tool
{
    protected string $name = 'semantic_cache_stats';

    protected string $description = 'Get semantic LLM response cache statistics: total entries, total hits saved, per-model breakdown, and current configuration.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        $base = SemanticCacheEntry::withoutGlobalScopes()->where('team_id', $teamId);

        $total = (clone $base)->count();
        $totalHits = (int) (clone $base)->sum('hit_count');
        $expired = (clone $base)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->count();

        $byModel = (clone $base)
            ->selectRaw('provider, model, COUNT(*) as entries, SUM(hit_count) as hits')
            ->groupBy('provider', 'model')
            ->orderByDesc('hits')
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'model' => $r->model,
                'entries' => (int) $r->entries,
                'hits' => (int) $r->hits,
            ])
            ->values()
            ->toArray();

        return Response::text(json_encode([
            'enabled' => config('semantic_cache.enabled', false),
            'similarity_threshold' => config('semantic_cache.similarity_threshold', 0.92),
            'ttl_days' => config('semantic_cache.ttl_days', 7),
            'total_entries' => $total,
            'total_hits_saved' => $totalHits,
            'expired_entries' => $expired,
            'by_model' => $byModel,
        ]));
    }
}
