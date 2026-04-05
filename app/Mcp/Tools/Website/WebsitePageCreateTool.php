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

    protected string $description = 'Create a new page in a website.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()
                ->description('Website UUID')
                ->required(),
            'slug' => $schema->string()
                ->description('Page URL slug (e.g. "about-us")')
                ->required(),
            'title' => $schema->string()
                ->description('Page display title')
                ->required(),
            'page_type' => $schema->string()
                ->description('Page type: page, post, product, landing')
                ->enum(['page', 'post', 'product', 'landing']),
            'exported_html' => $schema->string()
                ->description('HTML content for the page'),
            'exported_css' => $schema->string()
                ->description('CSS styles for the page'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));
        if (! $website) {
            return Response::error('Website not found.');
        }

        $validated = $request->validate([
            'slug' => 'required|string|max:100|regex:/^[a-z0-9-]+$/',
            'title' => 'required|string|max:255',
            'page_type' => 'nullable|string|in:page,post,product,landing',
            'exported_html' => 'nullable|string',
            'exported_css' => 'nullable|string',
        ]);

        try {
            $page = app(CreateWebsitePageAction::class)->execute(
                website: $website,
                data: [
                    'slug' => $validated['slug'],
                    'title' => $validated['title'],
                    'page_type' => $validated['page_type'] ?? 'page',
                    'exported_html' => $validated['exported_html'] ?? null,
                    'exported_css' => $validated['exported_css'] ?? null,
                ],
            );

            return Response::text(json_encode([
                'success' => true,
                'page_id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
