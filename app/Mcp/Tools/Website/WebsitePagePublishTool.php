<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Actions\UnpublishWebsitePageAction;
use App\Domain\Website\Models\WebsitePage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class WebsitePagePublishTool extends Tool
{
    protected string $name = 'website_page_publish';

    protected string $description = 'Publish or unpublish a website page.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()
                ->description('Page UUID')
                ->required(),
            'publish' => $schema->boolean()
                ->description('true to publish, false to unpublish')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $page = WebsitePage::find($request->get('page_id'));
        if (! $page) {
            return Response::error('Page not found.');
        }

        try {
            if ($request->get('publish', true)) {
                $page = app(PublishWebsitePageAction::class)->execute($page);
                $message = 'Page published.';
            } else {
                $page = app(UnpublishWebsitePageAction::class)->execute($page);
                $message = 'Page unpublished.';
            }

            return Response::text(json_encode([
                'success' => true,
                'message' => $message,
                'status' => $page->status->value,
                'published_at' => $page->published_at?->toISOString(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
