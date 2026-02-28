<?php

namespace App\Mcp\Tools\Cache;

use App\Infrastructure\AI\Models\SemanticCacheEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class SemanticCachePurgeTool extends Tool
{
    protected string $name = 'semantic_cache_purge';

    protected string $description = 'Purge semantic cache entries. Optionally filter by provider/model or purge only expired entries.';

    public function schema(JsonSchema $schema): array
    {
        return [
            $schema->string('provider')->description('Filter by provider (e.g. "anthropic"). Omit to purge all.')->nullable(),
            $schema->string('model')->description('Filter by model (e.g. "claude-sonnet-4-5-20250929"). Omit to purge all.')->nullable(),
            $schema->boolean('expired_only')->description('When true, only purge entries past their expiry date.')->nullable(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = auth()->user()?->current_team_id;

        $query = SemanticCacheEntry::withoutGlobalScopes()
            ->where('team_id', $teamId);

        if ($provider = $request->get('provider')) {
            $query->where('provider', $provider);
        }

        if ($model = $request->get('model')) {
            $query->where('model', $model);
        }

        if ($request->get('expired_only')) {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
        }

        $deleted = $query->delete();

        return Response::text(json_encode([
            'deleted' => $deleted,
            'filters' => [
                'provider' => $request->get('provider'),
                'model' => $request->get('model'),
                'expired_only' => (bool) $request->get('expired_only'),
            ],
        ]));
    }
}
