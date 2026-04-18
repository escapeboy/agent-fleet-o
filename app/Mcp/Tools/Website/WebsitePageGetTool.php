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

    protected string $description = 'Get a website page by ID including its GrapesJS JSON and exported HTML/CSS.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('The page UUID (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::find($request->get('page_id'));

        if (! $page) {
            return Response::text(json_encode(['error' => 'Page not found'], JSON_PRETTY_PRINT));
        }

        return Response::text(json_encode([
            'id' => $page->id,
            'website_id' => $page->website_id,
            'slug' => $page->slug,
            'title' => $page->title,
            'page_type' => $page->page_type instanceof \BackedEnum ? $page->page_type->value : $page->page_type,
            'status' => $page->status instanceof \BackedEnum ? $page->status->value : $page->status,
            'meta' => $page->meta,
            'grapes_json' => $page->grapes_json,
            'exported_html' => $page->exported_html,
            'exported_css' => $page->exported_css,
            'sort_order' => $page->sort_order,
            'published_at' => $page->published_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
