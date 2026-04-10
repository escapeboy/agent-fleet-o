<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsitePageUpdateTool extends Tool
{
    protected string $name = 'website_page_update';

    protected string $description = 'Update a website page\'s content, metadata, or status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('The page UUID (required)'),
            'title' => $schema->string()->description('New page title'),
            'slug' => $schema->string()->description('New URL slug'),
            'page_type' => $schema->string()->description('Page type')->enum(['page', 'post', 'product', 'landing']),
            'status' => $schema->string()->description('Page status')->enum(['draft', 'published']),
            'exported_html' => $schema->string()->description('The full HTML content to set'),
            'meta' => $schema->string()->description('JSON-encoded meta object (title, description, etc.)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::find($request->get('page_id'));

        if (! $page) {
            return Response::text(json_encode(['error' => 'Page not found'], JSON_PRETTY_PRINT));
        }

        $data = [];

        foreach (['title', 'slug', 'page_type', 'status', 'exported_html'] as $field) {
            if ($request->get($field) !== null) {
                $data[$field] = $request->get($field);
            }
        }

        if ($metaRaw = $request->get('meta')) {
            $data['meta'] = json_decode($metaRaw, true) ?? [];
        }

        $page = app(UpdateWebsitePageAction::class)->execute($page, $data);

        return Response::text(json_encode([
            'id' => $page->id,
            'title' => $page->title,
            'slug' => $page->slug,
            'status' => $page->status instanceof \BackedEnum ? $page->status->value : $page->status,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
