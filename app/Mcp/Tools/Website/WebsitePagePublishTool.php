<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsitePagePublishTool extends Tool
{
    protected string $name = 'website_page_publish';

    protected string $description = 'Publish a website page, making it publicly accessible.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('The page UUID to publish (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::find($request->get('page_id'));

        if (! $page) {
            return Response::text(json_encode(['error' => 'Page not found'], JSON_PRETTY_PRINT));
        }

        try {
            $page = app(PublishWebsitePageAction::class)->execute($page);
        } catch (\RuntimeException $e) {
            return Response::text(json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT));
        }

        return Response::text(json_encode([
            'success' => true,
            'published_at' => $page->published_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
