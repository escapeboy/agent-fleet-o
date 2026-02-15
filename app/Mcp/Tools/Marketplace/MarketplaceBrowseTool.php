<?php

namespace App\Mcp\Tools\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceListing;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class MarketplaceBrowseTool extends Tool
{
    protected string $name = 'marketplace_browse';

    protected string $description = 'Browse marketplace listings with optional type filter. Returns id, slug, title, type, status, description summary.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->description('Filter by listing type (e.g. skill, agent, workflow)'),
            'limit' => $schema->integer()
                ->description('Max results to return (default 10, max 100)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $query = MarketplaceListing::query()->orderByDesc('created_at');

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $limit = min((int) ($request->get('limit', 10)), 100);

        $listings = $query->limit($limit)->get();

        return Response::text(json_encode([
            'count' => $listings->count(),
            'listings' => $listings->map(fn ($l) => [
                'id' => $l->id,
                'slug' => $l->slug,
                'title' => $l->name ?? $l->slug,
                'type' => $l->type,
                'status' => $l->status->value,
                'description' => mb_substr($l->description ?? '', 0, 200),
            ])->toArray(),
        ]));
    }
}
