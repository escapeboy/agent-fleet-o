<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsitePageUnpublishTool extends Tool
{
    protected string $name = 'website_page_unpublish';

    protected string $description = 'Unpublish a website page, reverting it to draft status and removing it from public serving.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('The page UUID to unpublish (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::query()->find($request->get('page_id'));

        if (! $page) {
            return Response::text(json_encode(['error' => 'Page not found'], JSON_PRETTY_PRINT));
        }

        $page = app(UnpublishWebsitePageAction::class)->execute($page);

        return Response::text(json_encode([
            'success' => true,
            'page_id' => $page->id,
            'status' => $page->status->value,
            'published_at' => $page->published_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }
}
