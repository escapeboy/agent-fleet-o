<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\DeleteWebsiteAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsiteDeleteTool extends Tool
{
    protected string $name = 'website_delete';

    protected string $description = 'Permanently delete a website and all its pages and assets.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID to delete (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        app(DeleteWebsiteAction::class)->execute($website);

        return Response::text(json_encode(['success' => true], JSON_PRETTY_PRINT));
    }
}
