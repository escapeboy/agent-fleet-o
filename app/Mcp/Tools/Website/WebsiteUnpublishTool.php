<?php

namespace App\Mcp\Tools\Website;

use App\Domain\Website\Actions\UnpublishWebsiteAction;
use App\Domain\Website\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class WebsiteUnpublishTool extends Tool
{
    protected string $name = 'website_unpublish';

    protected string $description = 'Unpublish a website. Takes it offline and reverts all published pages to draft.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->string()->description('The website UUID to unpublish (required)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $website = Website::query()->find($request->get('website_id'));

        if (! $website) {
            return Response::text(json_encode(['error' => 'Website not found'], JSON_PRETTY_PRINT));
        }

        $website = app(UnpublishWebsiteAction::class)->execute($website);

        return Response::text(json_encode([
            'success' => true,
            'website_id' => $website->id,
            'status' => $website->status->value,
            'message' => "Website '{$website->name}' unpublished.",
        ], JSON_PRETTY_PRINT));
    }
}
