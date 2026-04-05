<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\UpdateWebsitePageAction;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsitePageUpdateTool extends Tool
{
    protected string $name = 'website_page_update';

    protected string $description = 'Update a page\'s content (HTML/CSS), title, slug, or meta. Use this to write HTML content for a page.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()
                ->description('Page UUID')
                ->required(),
            'title' => $schema->string()
                ->description('Page title'),
            'slug' => $schema->string()
                ->description('URL slug'),
            'exported_html' => $schema->string()
                ->description('HTML content (body HTML, not full document)'),
            'exported_css' => $schema->string()
                ->description('CSS styles'),
            'meta' => $schema->object()
                ->description('Page meta: title, description, og_image'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::find($request->get('page_id'));
        if (! $page) {
            return Response::error('Page not found.');
        }

        try {
            $page = app(UpdateWebsitePageAction::class)->execute(
                page: $page,
                data: array_filter([
                    'title' => $request->get('title'),
                    'slug' => $request->get('slug'),
                    'exported_html' => $request->get('exported_html'),
                    'exported_css' => $request->get('exported_css'),
                    'meta' => $request->get('meta'),
                ], fn ($v) => $v !== null),
            );

            return Response::text(json_encode([
                'success' => true,
                'page_id' => $page->id,
                'title' => $page->title,
                'has_content' => ! empty($page->exported_html),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
