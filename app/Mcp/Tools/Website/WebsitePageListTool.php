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

    protected string $description = 'List all pages for a website.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()
                ->description('Website UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));
        if (! $website) {
            return Response::error('Website not found.');
        }

        $pages = $website->pages()->orderBy('sort_order')->get();

        return Response::text(json_encode([
            'count' => $pages->count(),
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->title,
                'page_type' => $p->page_type->value,
                'status' => $p->status->value,
                'has_content' => ! empty($p->exported_html),
                'sort_order' => $p->sort_order,
                'published_at' => $p->published_at?->toISOString(),
            ])->toArray(),
        ]));
    }
}
