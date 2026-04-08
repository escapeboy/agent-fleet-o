<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\CreateWebsitePageAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsitePageCreateTool extends Tool
{
    protected string $name = 'website_page_create';

    protected string $description = 'Create a new page within a website.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID (required)'),
            'title' => $schema->string()->description('Page title (required)'),
            'slug' => $schema->string()->description('Page URL slug (required)'),
            'page_type' => $schema->string()->description('Page type (default: page)')->enum(['page', 'post', 'product', 'landing']),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $page = app(CreateWebsitePageAction::class)->execute($website, [
            'title' => $request->get('title'),
            'slug' => $request->get('slug'),
            'page_type' => $request->get('page_type') ?? 'page',
        ]);

        return Response::text(json_encode([
            'id' => $page->id,
            'website_id' => $page->website_id,
            'title' => $page->title,
            'slug' => $page->slug,
            'page_type' => $page->page_type instanceof \BackedEnum ? $page->page_type->value : $page->page_type,
            'status' => $page->status instanceof \BackedEnum ? $page->status->value : $page->status,
            'created_at' => $page->created_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
