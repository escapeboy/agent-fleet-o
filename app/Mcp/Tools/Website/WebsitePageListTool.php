<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebsitePageListTool extends Tool
{
    protected string $name = 'website_page_list';

    protected string $description = 'List pages for a website. Optionally filter by status or page type.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID (required)'),
            'status' => $schema->string()->description('Filter by status')->enum(['draft', 'published']),
            'page_type' => $schema->string()->description('Filter by page type')->enum(['page', 'post', 'product', 'landing']),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $query = $website->pages()->orderBy('sort_order');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->get('page_type')) {
            $query->where('page_type', $request->get('page_type'));
        }

        $pages = $query->get();

        return Response::text(json_encode([
            'count' => $pages->count(),
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->title,
                'page_type' => $p->page_type instanceof \BackedEnum ? $p->page_type->value : $p->page_type,
                'status' => $p->status instanceof \BackedEnum ? $p->status->value : $p->status,
                'sort_order' => $p->sort_order,
                'published_at' => $p->published_at?->toIso8601String(),
            ]),
        ], JSON_PRETTY_PRINT));
    }
}
