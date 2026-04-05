<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Models\WebsitePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class WebsitePageGetTool extends Tool
{
    protected string $name = 'website_page_get';

    protected string $description = 'Get a single website page including its exported HTML/CSS content.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()
                ->description('Page UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::find($request->get('page_id'));
        if (! $page) {
            return Response::error('Page not found.');
        }

        return Response::text(json_encode([
            'id' => $page->id,
            'website_id' => $page->website_id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type->value,
            'status' => $page->status->value,
            'meta' => $page->meta,
            'exported_html' => $page->exported_html,
            'exported_css' => $page->exported_css,
            'sort_order' => $page->sort_order,
            'published_at' => $page->published_at?->toISOString(),
        ]));
    }
}
